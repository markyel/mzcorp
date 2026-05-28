<?php

namespace App\Services\Mail;

use App\Enums\BlocklistEntrySource;
use App\Enums\BlocklistEntryType;
use App\Models\Request;
use App\Models\SenderBlocklistEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис управления стоп-листом отправителей.
 *
 * Все mutations проходят через этот сервис — он отвечает за нормализацию
 * (`normalize*`) и дедуп. Прямые `SenderBlocklistEntry::create()` в коде
 * pipeline'а — антипаттерн, ломают unique-constraint при неверном регистре
 * или plus-addressing.
 *
 * Матчинг (`isBlocked`):
 *   - email-запись    → точное совпадение нормализованных адресов.
 *   - domain-запись   → нормализованный домен письма равен записи ИЛИ
 *                       заканчивается на `.запись` (суффикс-матч с
 *                       разделителем `.`). Это исключает ложные срабатывания
 *                       типа `paulschaab.de.evil.com` для записи `paulschaab.de`.
 *
 * Кеш: для производительности при потоке писем нужен кеш активного списка.
 * Пока — без кеша, прямой SELECT. Если pipeline начнёт упираться — добавить
 * `Cache::remember('sender_blocklist', 60, …)` + инвалидация в `block/unblock`.
 */
class SenderBlocklistService
{
    /**
     * Заблокирован ли отправитель.
     *
     * Если да — увеличиваем hit_count матчнувшей записи и фиксируем last_hit_at.
     */
    public function isBlocked(?string $fromEmail): bool
    {
        $email = $this->normalizeEmail($fromEmail);
        if ($email === null) {
            return false;
        }

        $domain = $this->extractDomain($email);
        if ($domain === null) {
            return false;
        }

        // 1) Точное совпадение по email (нормализованному).
        $emailMatch = SenderBlocklistEntry::query()
            ->where('type', BlocklistEntryType::Email->value)
            ->where('normalized_value', $email)
            ->first();

        if ($emailMatch) {
            $this->registerHit($emailMatch);

            return true;
        }

        // 2) Domain-записи: суффикс-матч.
        // Берём все domain-записи которые являются суффиксом домена письма.
        // На реальных объёмах (десятки-сотни записей) этот SELECT тривиален.
        $domainCandidates = SenderBlocklistEntry::query()
            ->where('type', BlocklistEntryType::Domain->value)
            ->get();

        foreach ($domainCandidates as $entry) {
            if ($this->domainMatches($domain, $entry->normalized_value)) {
                $this->registerHit($entry);

                return true;
            }
        }

        return false;
    }

    /**
     * Добавить запись. Идемпотентно: если такая нормализованная запись уже
     * есть — возвращает существующую (без модификации). Иначе создаёт.
     *
     * @throws \InvalidArgumentException если value некорректен для type
     */
    public function block(
        string $rawValue,
        BlocklistEntryType $type,
        BlocklistEntrySource $source,
        ?User $byUser = null,
        ?Request $fromRequest = null,
        ?string $comment = null,
    ): SenderBlocklistEntry {
        $normalized = $this->normalizeFor($type, $rawValue);
        if ($normalized === null) {
            throw new \InvalidArgumentException(
                "Невалидное значение для стоп-листа (type={$type->value}): {$rawValue}"
            );
        }

        // findOrCreate — атомарно за счёт unique-constraint.
        return DB::transaction(function () use ($type, $rawValue, $normalized, $source, $byUser, $fromRequest, $comment) {
            $existing = SenderBlocklistEntry::query()
                ->where('type', $type->value)
                ->where('normalized_value', $normalized)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return SenderBlocklistEntry::create([
                'type' => $type->value,
                'value' => trim($rawValue),
                'normalized_value' => $normalized,
                'source' => $source->value,
                'comment' => $comment,
                'added_by_user_id' => $byUser?->id,
                'added_from_request_id' => $fromRequest?->id,
            ]);
        });
    }

    /**
     * Удалить запись (полностью).
     */
    public function unblock(int $entryId): bool
    {
        return SenderBlocklistEntry::query()->whereKey($entryId)->delete() > 0;
    }

    /**
     * Массовое добавление: массив строк, каждая — отдельный value.
     * Тип определяется автоматически (есть `@` → email, иначе domain).
     * Возвращает [created, skipped] счётчики.
     *
     * @param  string[]  $values
     * @return array{created: int, skipped: int, invalid: string[]}
     */
    public function bulkBlock(
        array $values,
        BlocklistEntrySource $source,
        ?User $byUser = null,
        ?string $comment = null,
    ): array {
        $created = 0;
        $skipped = 0;
        $invalid = [];

        foreach ($values as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }

            $type = str_contains($raw, '@') ? BlocklistEntryType::Email : BlocklistEntryType::Domain;

            try {
                $entry = $this->block($raw, $type, $source, $byUser, null, $comment);
                if ($entry->wasRecentlyCreated) {
                    $created++;
                } else {
                    $skipped++;
                }
            } catch (\InvalidArgumentException) {
                $invalid[] = $raw;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'invalid' => $invalid];
    }

    /**
     * Нормализация email: lowercase, trim, plus-addressing срезается.
     * Возвращает null, если не похоже на email.
     */
    public function normalizeEmail(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = mb_strtolower(trim($raw));
        if ($raw === '' || ! str_contains($raw, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $raw, 2);
        if ($local === '' || $domain === '') {
            return null;
        }

        // Plus-addressing: foo+bar@x.ru → foo@x.ru.
        // RFC 5233 «subaddressing» — стандартный приём защиты от спама,
        // и наоборот, спамеры им же иногда обходят простые блокировки.
        $plusPos = strpos($local, '+');
        if ($plusPos !== false) {
            $local = substr($local, 0, $plusPos);
            if ($local === '') {
                return null;
            }
        }

        $domain = $this->normalizeDomain($domain);
        if ($domain === null) {
            return null;
        }

        return $local.'@'.$domain;
    }

    /**
     * Нормализация домена: lowercase, trim, без trailing dot, без ведущих
     * `@`/`http://`/`https://` и пути. Возвращает null если результат пуст
     * или не выглядит доменом (нет точки).
     */
    public function normalizeDomain(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = mb_strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }

        // Срезаем URL-обёртки если кто-то ввёл `https://paulschaab.de/`.
        $raw = preg_replace('#^https?://#', '', $raw);
        $raw = ltrim($raw, '@');
        // Срезаем путь после первого `/`.
        $slashPos = strpos($raw, '/');
        if ($slashPos !== false) {
            $raw = substr($raw, 0, $slashPos);
        }
        // Trailing dot (FQDN-форма).
        $raw = rtrim($raw, '.');

        if ($raw === '' || ! str_contains($raw, '.')) {
            return null;
        }

        return $raw;
    }

    /**
     * Нормализация под тип записи.
     */
    public function normalizeFor(BlocklistEntryType $type, string $raw): ?string
    {
        return match ($type) {
            BlocklistEntryType::Email => $this->normalizeEmail($raw),
            BlocklistEntryType::Domain => $this->normalizeDomain($raw),
        };
    }

    /**
     * Извлечь домен из нормализованного email.
     */
    private function extractDomain(string $normalizedEmail): ?string
    {
        $atPos = strrpos($normalizedEmail, '@');
        if ($atPos === false) {
            return null;
        }
        $domain = substr($normalizedEmail, $atPos + 1);

        return $domain !== '' ? $domain : null;
    }

    /**
     * Совпадает ли домен с записью стоп-листа (суффикс-матч).
     *
     * Условие: $domain === $entry  ИЛИ  $domain заканчивается на `.$entry`.
     * Это гарантирует, что `paulschaab.de.evil.com` НЕ матчится записью
     * `paulschaab.de`.
     */
    private function domainMatches(string $domain, string $entryDomain): bool
    {
        if ($domain === $entryDomain) {
            return true;
        }
        $suffix = '.'.$entryDomain;

        return str_ends_with($domain, $suffix);
    }

    /**
     * Инкремент hit_count + last_hit_at. Изолировано от транзакций основного
     * pipeline'а — счётчик чисто аналитический, его сбой не должен валить
     * processing письма.
     */
    private function registerHit(SenderBlocklistEntry $entry): void
    {
        try {
            $entry->forceFill([
                'hit_count' => $entry->hit_count + 1,
                'last_hit_at' => now(),
            ])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('SenderBlocklist hit-counter update failed', [
                'entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
