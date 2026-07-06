<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\LearnedArticleAlias;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Обучаемые привязки «код клиента → каталожная позиция».
 *
 * Идея заказчика (2026-07-06): менеджер вручную привязывает позицию с кодом,
 * который автоматчинг не нашёл, к M-позиции каталога → запоминаем. Когда одно
 * и то же соответствие подтверждено повторно (confirmations >= 2) и не имеет
 * конкурентов, автоматчинг применяет его как шаг D (после точных A/B, до
 * векторного C). Кейс-мотиватор: клиентский «52513669» против каталожной
 * склейки articles=["52513669 + 52517495"] — точный матч бессилен, менеджеры
 * каждый раз привязывают руками одну и ту же позицию.
 *
 * Каталог 1С не трогаем (правило №7) — словарь живёт в нашей БД и переживает
 * импорты каталога.
 */
class LearnedAliasService
{
    /** Минимум подтверждений, чтобы алиас применялся автоматчингом. */
    public const MIN_CONFIRMATIONS = 2;

    /**
     * Запомнить ручную привязку позиции к каталогу. Учим каждый информативный
     * токен parsed_article, который НЕ нашёлся бы точным матчем (иначе шум):
     * идея — запоминать именно те коды, где автоматика бессильна.
     */
    public function learnFromManualLink(RequestItem $item, CatalogItem $catalog, ?User $author = null): void
    {
        $article = trim((string) ($item->parsed_article ?? ''));
        if ($article === '') {
            return;
        }

        $tokens = preg_split('/\s*[,\/]\s*/', $article) ?: [$article];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '' || LocalSupplierCodePattern::isLocalToken($tok)) {
                continue;
            }
            $norm = CatalogImportService::normalizeArticle($tok);
            if ($norm === null || mb_strlen($norm) < 4) {
                continue;
            }
            // Код и так находится точным матчем у ЭТОЙ позиции — не учим.
            if ($this->exactMatchable($norm, $catalog)) {
                continue;
            }

            $alias = LearnedArticleAlias::query()
                ->where('article_normalized', $norm)
                ->where('catalog_item_id', $catalog->id)
                ->first();
            if ($alias !== null) {
                $alias->forceFill([
                    'confirmations' => $alias->confirmations + 1,
                    'sample_article' => mb_substr($tok, 0, 190),
                    'sample_name' => mb_substr((string) $item->parsed_name, 0, 190) ?: $alias->sample_name,
                    'last_confirmed_at' => now(),
                    'last_confirmed_by_user_id' => $author?->id,
                ])->save();
            } else {
                $alias = LearnedArticleAlias::create([
                    'article_normalized' => $norm,
                    'catalog_item_id' => $catalog->id,
                    'confirmations' => 1,
                    'sample_article' => mb_substr($tok, 0, 190),
                    'sample_name' => mb_substr((string) $item->parsed_name, 0, 190) ?: null,
                    'last_confirmed_at' => now(),
                    'last_confirmed_by_user_id' => $author?->id,
                ]);
            }

            Log::info('LearnedAlias: manual link recorded', [
                'article_normalized' => $norm,
                'catalog_item_id' => $catalog->id,
                'catalog_sku' => $catalog->sku,
                'confirmations' => $alias->confirmations,
                'request_item_id' => $item->id,
            ]);
        }
    }

    /**
     * Лучший выученный алиас для нормализованного кода: подтверждений >=
     * MIN_CONFIRMATIONS и строгое лидерство (конфликтующие привязки одного
     * кода к разным позициям с равным счётом — не применяем, ждём перевеса).
     */
    public function lookup(string $norm): ?CatalogItem
    {
        $top = LearnedArticleAlias::query()
            ->where('article_normalized', $norm)
            ->whereHas('catalogItem', fn ($q) => $q->where('is_active', true))
            ->orderByDesc('confirmations')
            ->orderByDesc('last_confirmed_at')
            ->limit(2)
            ->get();
        if ($top->isEmpty()) {
            return null;
        }
        /** @var LearnedArticleAlias $best */
        $best = $top->first();
        if ($best->confirmations < self::MIN_CONFIRMATIONS) {
            return null;
        }
        if ($top->count() > 1 && (int) $top[1]->confirmations >= (int) $best->confirmations) {
            return null; // конфликт без явного лидера
        }

        return $best->catalogItem;
    }

    /** Нашёлся бы код точным матчем (sku / brand_article / articles[]) у этой позиции? */
    private function exactMatchable(string $norm, CatalogItem $catalog): bool
    {
        if (CatalogImportService::normalizeArticle((string) $catalog->sku) === $norm) {
            return true;
        }
        if ((string) $catalog->brand_article_normalized === $norm) {
            return true;
        }
        foreach ((array) ($catalog->articles ?? []) as $a) {
            if ($a !== null && CatalogImportService::normalizeArticle((string) $a) === $norm) {
                return true;
            }
        }

        return false;
    }
}
