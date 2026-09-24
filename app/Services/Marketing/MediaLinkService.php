<?php

namespace App\Services\Marketing;

use App\Models\CatalogItem;

/**
 * Ссылки на карточки товара в тексте публикации.
 *
 * Модель про адреса ничего не знает и знать не должна: она пишет «· арт.
 * M25447», а ссылку подставляем мы при отправке — по тому же шаблону, что и
 * товарный фид Директа, только с меткой своего канала.
 *
 * Делается это по-разному, потому что площадки разные:
 *   Telegram размечает ссылки HTML-ом, и адрес не съедает лимит подписи
 *   (1024 знака считаются по видимому тексту) — значит артикул можно просто
 *   сделать ссылкой;
 *   ВК разметки в тексте не понимает, там адрес приходится писать целиком,
 *   зато и лимит поста огромный.
 */
class MediaLinkService
{
    /** Артикул каталога: M + цифры. Именно в таком виде его пишет модель. */
    private const SKU_RE = '/\bM\d{4,6}\b/u';

    /**
     * Адрес карточки товара с меткой канала.
     */
    public function productUrl(string $sku, string $channelKind): string
    {
        $pattern = (string) (config('services.marketing.product_url')
            ?: config('services.yandex_direct.feed.product_url', 'https://www.mylift.ru/ru/?com=shop&srv=product&code={sku}'));
        $url = str_replace('{sku}', rawurlencode($sku), $pattern);

        $utm = [
            'utm_source' => $channelKind,
            'utm_medium' => 'social',
            'utm_campaign' => 'media_plan',
            'utm_content' => $sku,
        ];

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($utm);
    }

    /**
     * Артикулы в тексте → ссылки на карточки.
     *
     * Возвращаем текст и признак «размечено HTML-ом»: от него зависит
     * parse_mode при отправке в Telegram.
     *
     * @return array{text: string, html: bool}
     */
    public function linkify(string $text, string $channelKind): array
    {
        if (! preg_match_all(self::SKU_RE, $text, $m)) {
            return ['text' => $text, 'html' => false];
        }

        // Ссылки ставим только на то, что и правда есть в каталоге: иначе
        // «M12345» из чужого письма увело бы читателя на пустую страницу.
        $known = CatalogItem::query()
            ->whereIn('sku', array_unique($m[0]))
            ->pluck('sku')
            ->all();
        if ($known === []) {
            return ['text' => $text, 'html' => false];
        }

        if ($channelKind === 'telegram') {
            // Экранируем весь текст ДО вставки тегов: иначе «<» из названия
            // позиции сломает разметку сообщения.
            $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
            $linked = preg_replace_callback(
                self::SKU_RE,
                function (array $hit) use ($known, $channelKind) {
                    if (! in_array($hit[0], $known, true)) {
                        return $hit[0];
                    }

                    return '<a href="'.htmlspecialchars($this->productUrl($hit[0], $channelKind), ENT_QUOTES).'">'.$hit[0].'</a>';
                },
                $escaped,
            );

            return ['text' => (string) $linked, 'html' => true];
        }

        // ВК и прочие: адрес отдельной строкой под позицией, по одному на артикул.
        $seen = [];
        $linked = preg_replace_callback(
            self::SKU_RE,
            function (array $hit) use ($known, $channelKind, &$seen) {
                if (! in_array($hit[0], $known, true) || isset($seen[$hit[0]])) {
                    return $hit[0];
                }
                $seen[$hit[0]] = true;

                return $hit[0]."\n  ".$this->productUrl($hit[0], $channelKind);
            },
            $text,
        );

        return ['text' => (string) $linked, 'html' => false];
    }
}
