<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Models\AddressBookContact;
use App\Models\ClientContact;
use App\Models\Mailbox;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Адресная книга почты — подсказки в полях «Кому / Копия / Скрытая копия»
 * и окно выбора адресатов в обоих композерах (почта и карточка заявки).
 *
 * Источники, по приоритету при совпадении адреса:
 *   mine      — личные контакты пользователя (address_book_contacts);
 *   recent    — кому он писал за последние полгода (исходящие его ящиков);
 *   colleague — ящики компании: личные сотрудников и общие;
 *   client    — контакты клиентов из реестра (client_contacts);
 *   supplier  — поставщики (suppliers).
 *
 * Запись: ['email', 'name', 'org', 'source', 'id' (только у mine)].
 */
class AddressBookService
{
    public const SOURCES = ['recent', 'mine', 'colleague', 'client', 'supplier'];

    /** Сколько дней истории исходящих смотрим для «недавних». */
    public const RECENT_DAYS = 180;

    /** Сколько недавних адресатов держим в кэше на пользователя. */
    public const RECENT_LIMIT = 300;

    public const RECENT_CACHE_TTL = 600;

    /**
     * Подсказки по вводу. Пустой ввод — самые частые недавние адресаты.
     *
     * @return list<array{email:string, name:?string, org:?string, source:string, id?:int}>
     */
    public function suggest(User $user, string $term, int $limit = 10): array
    {
        $term = trim($term);
        if ($term === '') {
            return array_slice($this->recent($user), 0, $limit);
        }

        $merged = $this->dedupe(array_merge(
            $this->mine($user, $term, $limit),
            $this->filterRecent($this->recent($user), $term, $limit),
            $this->colleagues($term, $limit),
            $this->clients($term, $limit),
            $this->suppliers($term, $limit),
        ));

        // Совпадение с начала адреса или слова в имени — выше, порядок
        // источников внутри группы сохраняется (usort стабилен в PHP 8).
        $needle = mb_strtolower($term);
        usort($merged, fn ($a, $b) => $this->startsWith($b, $needle) <=> $this->startsWith($a, $needle));

        return array_slice($merged, 0, $limit);
    }

    /**
     * Вкладка окна адресной книги.
     *
     * @return list<array{email:string, name:?string, org:?string, source:string, id?:int}>
     */
    public function browse(User $user, string $source, string $term, int $limit = 100): array
    {
        $term = trim($term);

        return match ($source) {
            'mine' => $this->mine($user, $term, $limit),
            'colleague' => $this->colleagues($term, $limit),
            'client' => $this->clients($term, $limit),
            'supplier' => $this->suppliers($term, $limit),
            default => $this->filterRecent($this->recent($user), $term, $limit),
        };
    }

    /** Добавить или обновить личный контакт (по адресу). */
    public function saveContact(User $user, string $email, ?string $name, ?string $organization): AddressBookContact
    {
        $contact = AddressBookContact::query()->firstOrNew([
            'user_id' => $user->id,
            'email' => mb_strtolower(trim($email)),
        ]);
        $contact->name = $this->clean($name);
        $contact->organization = $this->clean($organization);
        $contact->save();

        return $contact;
    }

    public function deleteContact(User $user, int $id): bool
    {
        return AddressBookContact::query()->where('user_id', $user->id)->whereKey($id)->delete() > 0;
    }

    /* ------------------------------------------------------------------ */

    /** @return list<array> */
    private function mine(User $user, string $term, int $limit): array
    {
        return AddressBookContact::query()
            ->where('user_id', $user->id)
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $like = $this->like($term);
                $w->where('email', 'ilike', $like)
                    ->orWhere('name', 'ilike', $like)
                    ->orWhere('organization', 'ilike', $like);
            }))
            ->orderByRaw('coalesce(name, email)')
            ->limit($limit)
            ->get()
            ->map(fn (AddressBookContact $c) => [
                'email' => $c->email,
                'name' => $c->name,
                'org' => $c->organization,
                'source' => 'mine',
                'id' => (int) $c->id,
            ])
            ->all();
    }

    /**
     * Кому пользователь писал: исходящие его личных ящиков и письма, которые
     * он сам отправил из общих, за RECENT_DAYS. Самые частые — первыми.
     *
     * @return list<array>
     */
    public function recent(User $user): array
    {
        return Cache::remember('address-book:recent:'.$user->id, self::RECENT_CACHE_TTL, function () use ($user) {
            $ownMailboxIds = Mailbox::query()
                ->where('owner_user_id', $user->id)
                ->where('type', MailboxType::Personal->value)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ownMailboxes = $ownMailboxIds === [] ? 'NULL' : implode(',', $ownMailboxIds);

            // Литералы id — только int-cast выше; recipients — json/jsonb, приводим к jsonb.
            $rows = DB::select(<<<SQL
                SELECT lower(r->>'email') AS email, max(r->>'name') AS name, count(*) AS uses, max(m.sent_at) AS last_at
                FROM email_messages m,
                     jsonb_array_elements(
                         coalesce(m.to_recipients::jsonb, '[]'::jsonb)
                         || coalesce(m.cc_recipients::jsonb, '[]'::jsonb)
                         || coalesce(m.bcc_recipients::jsonb, '[]'::jsonb)
                     ) AS r
                WHERE m.direction = 'outbound'
                  AND m.is_draft = false
                  AND m.sent_at >= ?
                  AND (m.mailbox_id IN ({$ownMailboxes}) OR m.draft_author_user_id = ?)
                  AND coalesce(r->>'email', '') <> ''
                GROUP BY lower(r->>'email')
                ORDER BY uses DESC, last_at DESC
                LIMIT ?
            SQL, [now()->subDays(self::RECENT_DAYS), $user->id, self::RECENT_LIMIT]);

            return array_map(fn ($r) => [
                'email' => (string) $r->email,
                'name' => $this->clean($r->name),
                'org' => null,
                'source' => 'recent',
            ], $rows);
        });
    }

    /**
     * @param  list<array>  $recent
     * @return list<array>
     */
    private function filterRecent(array $recent, string $term, int $limit): array
    {
        if ($term === '') {
            return array_slice($recent, 0, $limit);
        }
        $needle = mb_strtolower($term);

        return array_slice(array_values(array_filter($recent, fn ($r) => str_contains($r['email'], $needle)
            || str_contains(mb_strtolower((string) $r['name']), $needle))), 0, $limit);
    }

    /** Ящики компании: личные сотрудников (с именем владельца) и общие. @return list<array> */
    private function colleagues(string $term, int $limit): array
    {
        return Mailbox::query()
            ->where('mailboxes.is_active', true)
            ->leftJoin('users', 'users.id', '=', 'mailboxes.owner_user_id')
            ->where(fn ($q) => $q->whereNull('mailboxes.owner_user_id')->orWhereNull('users.archived_at'))
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $like = $this->like($term);
                $w->where('mailboxes.email', 'ilike', $like)
                    ->orWhere('mailboxes.name', 'ilike', $like)
                    ->orWhere('users.name', 'ilike', $like);
            }))
            ->orderByRaw("CASE mailboxes.type WHEN 'personal' THEN 0 ELSE 1 END")
            ->orderByRaw('coalesce(users.name, mailboxes.name, mailboxes.email)')
            ->limit($limit)
            ->get(['mailboxes.email', 'mailboxes.name', 'mailboxes.type', 'users.name as owner_name'])
            ->map(fn ($m) => [
                'email' => mb_strtolower((string) $m->email),
                'name' => $this->clean($m->owner_name ?: $m->name),
                'org' => $m->type === MailboxType::Shared ? 'Общий ящик' : 'Сотрудник',
                'source' => 'colleague',
            ])
            ->all();
    }

    /** Контакты клиентов из реестра. @return list<array> */
    private function clients(string $term, int $limit): array
    {
        return ClientContact::query()
            ->whereNotNull('email')
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $like = $this->like($term);
                $w->where('email', 'ilike', $like)
                    ->orWhere('full_name', 'ilike', $like)
                    ->orWhereHas('organizations', fn ($o) => $o->where('name', 'ilike', $like));
            }))
            ->with('organizations:id,name')
            ->orderByRaw('coalesce(full_name, email)')
            ->limit($limit)
            ->get(['id', 'email', 'full_name'])
            ->map(fn (ClientContact $c) => [
                'email' => mb_strtolower((string) $c->email),
                'name' => $this->clean($c->full_name),
                'org' => $this->clean($c->organizations->first()?->name),
                'source' => 'client',
            ])
            ->all();
    }

    /** Поставщики. @return list<array> */
    private function suppliers(string $term, int $limit): array
    {
        return Supplier::query()
            ->whereNotNull('email')
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $like = $this->like($term);
                $w->where('email', 'ilike', $like)
                    ->orWhere('name', 'ilike', $like)
                    ->orWhere('contact_person', 'ilike', $like);
            }))
            ->orderBy('name')
            ->limit($limit)
            ->get(['email', 'name', 'contact_person'])
            ->map(fn (Supplier $s) => [
                'email' => mb_strtolower((string) $s->email),
                'name' => $this->clean($s->contact_person ?: $s->name),
                'org' => $s->contact_person ? $this->clean($s->name) : 'Поставщик',
                'source' => 'supplier',
            ])
            ->all();
    }

    /**
     * Один адрес — одна строка: остаётся запись из более приоритетного
     * источника, недостающие имя и организация добираются из следующих.
     *
     * @param  list<array>  $rows
     * @return list<array>
     */
    private function dedupe(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $row['email'];
            if ($key === '' || ! str_contains($key, '@')) {
                continue;
            }
            if (! isset($out[$key])) {
                $out[$key] = $row;

                continue;
            }
            $out[$key]['name'] ??= $row['name'];
            $out[$key]['org'] ??= $row['org'];
        }

        return array_values($out);
    }

    private function startsWith(array $row, string $needle): int
    {
        if (str_starts_with($row['email'], $needle)) {
            return 1;
        }
        foreach (preg_split('/[\s"«»().-]+/u', mb_strtolower((string) $row['name'])) ?: [] as $word) {
            if ($word !== '' && str_starts_with($word, $needle)) {
                return 1;
            }
        }

        return 0;
    }

    private function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }

    /**
     * Имя без символов, ломающих строку адресатов «Имя <email>, …»
     * (разделители и угловые скобки), и без лишних пробелов.
     */
    private function clean(?string $value): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', str_replace([',', ';', '<', '>', '"'], ' ', (string) $value)) ?? '');

        return $v === '' ? null : $v;
    }
}
