<?php

namespace App\Services\QuoteParts;

use App\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Приглашение поставщиков из справочника mzcorp в QuoteParts и статус приглашения.
 * Поставщик получает письмо от имени Моего Зипа (EN + ZH), по ссылке принимает
 * предложенный режим цены или предлагает свой.
 */
class QuotePartsSupplierService
{
    public const STATUS_LABELS = [
        'invited' => 'Приглашён',
        'terms_discussed' => 'Предлагает другие условия',
        'active' => 'Работаем',
        'declined' => 'Отказался от платформы',
        'mail' => 'Переписка',
    ];

    public function __construct(private readonly QuotePartsClient $client) {}

    /**
     * Тихо завести поставщика-организацию в QuoteParts (без писем, внесистемный режим) со всеми
     * его адресами: первый адрес — основной контакт, у остальных — их темы или «Расценка». Адрес,
     * который QuoteParts знает за другой компанией, пропускается. Копия — сразу (SupplierMirrorService).
     *
     * @return array{link: array<string, mixed>, skipped: int}
     */
    public function registerOrganization(\App\Models\SupplierOrganization $org, int $paceMicroseconds = 0): array
    {
        $contacts = Supplier::query()->where('supplier_organization_id', $org->id)->whereNotNull('email')
            ->orderByRaw("case when source = 'onec' then 1 else 0 end")->orderByRaw('contact_position nulls last')->orderBy('id')->get();
        if ($contacts->isEmpty()) {
            throw new \RuntimeException('у поставщика нет ни одного адреса');
        }
        $locale = $contacts->contains('language', 'en') ? 'en' : 'ru';
        $taken = [];

        // Первый адрес, который QuoteParts примет, — контакт регистрации.
        $link = null;
        foreach ($contacts as $first) {
            usleep($paceMicroseconds);
            try {
                $link = $this->client->registerSupplier([
                    'external_id' => $org->qp_key,
                    'company_name' => $org->name,
                    'contact' => ['email' => $first->email, 'name' => $first->contact_person ?: null, 'locale' => $first->language === 'en' ? 'en' : $locale],
                ]);
                break;
            } catch (\RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'другой компании')) {
                    throw $e;
                }
                $taken[mb_strtolower($first->email)] = true;
            }
        }
        if ($link === null) {
            throw new \RuntimeException('все адреса QuoteParts знает за другими компаниями');
        }

        $rows = fn () => $contacts->reject(fn ($s) => isset($taken[mb_strtolower($s->email)]))->values()
            ->map(fn ($s, $i) => array_filter([
                'email' => $s->email,
                'name' => $s->contact_person ?: null,
                'topics' => $i === 0 ? array_values(array_unique([...((array) ($s->contact_topics ?: ['quoting'])), 'general'])) : ((array) ($s->contact_topics ?: ['quoting'])),
                'position' => $i + 1,
                'locale' => $s->language === 'en' ? 'en' : $locale,
                'phone' => $s->phone ? mb_substr((string) $s->phone, 0, 40) : null,
            ], fn ($v) => $v !== null))->take(30)->all();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            usleep($paceMicroseconds);
            try {
                $link = $this->client->setContacts($org->qp_key, ['dispatch_mode' => 'primary', 'contacts' => $rows()]);
                break;
            } catch (\RuntimeException $e) {
                if (! preg_match('/Адрес (\S+) уже принадлежит другой компании/u', $e->getMessage(), $m)) {
                    throw $e;
                }
                $taken[mb_strtolower($m[1])] = true;
            }
        }

        // Название — чистое (без внутренних пометок 1С), пометки — в примечания: их видим только мы.
        if (($link['status'] ?? null) !== 'active') {
            usleep($paceMicroseconds);
            $link = $this->client->updateSupplier($org->qp_key, array_filter([
                'company_name' => $org->name,
                'profile' => $org->notes ? ['notes' => mb_substr((string) $org->notes, 0, 5000)] : null,
            ]));
        }

        Supplier::query()->where('supplier_organization_id', $org->id)->update(['quoteparts_offsystem' => ($link['status'] ?? null) !== 'active']);
        app(SupplierMirrorService::class)->apply($link);

        return ['link' => $link, 'skipped' => count($taken)];
    }

    /**
     * Режим переписки: адрес поставщика должен быть известен QuoteParts, чтобы отправить ему
     * запрос — без письма-приглашения. Уже заведённый адрес не трогаем.
     */
    public function ensureRegistered(Supplier $supplier): Supplier
    {
        if ($supplier->quoteparts_link_id !== null || trim((string) $supplier->email) === '') {
            return $supplier;
        }

        $result = $this->client->registerSupplier([
            'external_id' => $supplier->quotepartsKey(),
            'company_name' => (string) ($supplier->organization?->name ?: $supplier->name ?: $supplier->domain ?: $supplier->email),
            'contact' => [
                'email' => $supplier->email,
                'name' => $supplier->contact_person ?: null,
                'locale' => $supplier->language === 'ru' ? 'ru' : 'en',
            ],
        ]);

        $supplier->forceFill([
            'quoteparts_link_id' => $result['id'],
            'quoteparts_status' => $result['status'],
            'quoteparts_comment' => $result['supplier_comment'] ?? null,
        ])->save();

        return $supplier;
    }

    /**
     * @param  array{company_name:string, contact_name:?string, email:string, locale:string, country:?string, incoterm:?string, incoterm_place:?string, currency:?string, payment_terms:?string, packaging_included:?bool, offer_validity_days:?int, message:?string}  $form
     */
    public function invite(Supplier $supplier, array $form): Supplier
    {
        $result = $this->client->inviteSupplier([
            // По организации: второй адрес той же фирмы станет вторым сотрудником того же
            // поставщика в QuoteParts, а не отдельным поставщиком.
            'external_id' => $supplier->quotepartsKey(),
            'company_name' => $form['company_name'],
            'country' => $form['country'] ?: null,
            'contact' => [
                'email' => $form['email'],
                'name' => $form['contact_name'] ?: null,
                'locale' => $form['locale'],
            ],
            'terms' => array_filter([
                'incoterm' => $form['incoterm'] ?: null,
                'incoterm_place' => $form['incoterm_place'] ?: null,
                'currency' => $form['currency'] ?: null,
                'payment_terms' => $form['payment_terms'] ?: null,
                'packaging_included' => $form['packaging_included'],
                'offer_validity_days' => $form['offer_validity_days'] ?: null,
            ], fn ($v) => $v !== null),
            'message' => $form['message'] ?: null,
            'show_internal_code' => (bool) ($form['show_internal_code'] ?? false),
            // Группы товаров, которые поставщик видит (null — все).
            'groups' => $form['groups'] ?? null,
        ]);

        $supplier->forceFill([
            'quoteparts_link_id' => $result['id'],
            'quoteparts_status' => $result['status'],
            'quoteparts_invited_at' => now(),
            'quoteparts_comment' => $result['supplier_comment'] ?? null,
        ])->save();
        $this->rememberGroups($supplier, $form['groups'] ?? null);

        return $supplier;
    }

    /**
     * Какие группы товаров поставщик видит в кабинете QuoteParts (null — все). Поставщик в
     * QuoteParts — организация: копия запоминается у всех её адресов.
     *
     * @param  list<string>|null  $groups
     */
    public function setGroups(Supplier $supplier, ?array $groups): void
    {
        $this->client->setSupplierGroups($supplier->quotepartsKey(), $groups);
        $this->rememberGroups($supplier, $groups);
    }

    /**
     * Поставщик — агент (UniSystem): ищет поставщиков для нас, в каждой цене называет реального, и на позицию
     * живут несколько его цен (по поставщику). Признак — у связи в QuoteParts; копия — у всех адресов организации.
     */
    public function setAgent(Supplier $supplier, bool $agent): void
    {
        $this->client->setAgent($supplier->quotepartsKey(), $agent);
        Supplier::query()
            ->when($supplier->supplier_organization_id, fn ($q, $org) => $q->where('supplier_organization_id', $org), fn ($q) => $q->whereKey($supplier->id))
            ->update(['quoteparts_agent' => $agent]);
        $supplier->quoteparts_agent = $agent;
    }

    /** @param list<string>|null $groups */
    private function rememberGroups(Supplier $supplier, ?array $groups): void
    {
        Supplier::query()
            ->when($supplier->supplier_organization_id, fn ($q, $org) => $q->where('supplier_organization_id', $org), fn ($q) => $q->whereKey($supplier->id))
            ->update(['quoteparts_groups' => $groups === null ? null : json_encode(array_values($groups))]);
        $supplier->quoteparts_groups = $groups;
    }

    /**
     * Контакты поставщика в QuoteParts (кто ведёт расценку, финансы, логистику, рекламации)
     * и куда идёт автоматический перезапрос: главному ящику или по очереди.
     *
     * @return array<string, mixed>|null  связь из QuoteParts или null, если поставщик не приглашён
     */
    public function link(Supplier $supplier): ?array
    {
        return collect($this->client->supplierLinks([$supplier->quotepartsKey()]))->first();
    }

    /**
     * @param  list<array{email:string, name:?string, topics:list<string>, position:int}>  $contacts
     * @return array<string, mixed>
     */
    public function saveContacts(Supplier $supplier, array $contacts, string $mode): array
    {
        return $this->client->setContacts($supplier->quotepartsKey(), [
            'dispatch_mode' => $mode,
            'contacts' => $contacts,
        ]);
    }

    /**
     * Подтянуть статусы приглашённых поставщиков (принял, предложил другие условия, отказался).
     * Связь в QuoteParts — по организации: статус получают все её адреса.
     */
    public function refreshStatuses(): int
    {
        $updated = 0;

        Supplier::query()->whereNotNull('quoteparts_link_id')->select(['id', 'supplier_organization_id', 'quoteparts_status', 'quoteparts_comment'])
            ->chunkById(500, function ($suppliers) use (&$updated) {
                try {
                    $links = collect($this->client->supplierLinks($suppliers->map->quotepartsKey()->unique()->values()->all()))
                        ->keyBy('external_id');
                } catch (Throwable $e) {
                    Log::warning('QuoteParts supplier status refresh failed', ['error' => $e->getMessage()]);

                    return false;
                }

                foreach ($suppliers as $supplier) {
                    $link = $links->get($supplier->quotepartsKey());
                    if ($link === null) {
                        continue;
                    }
                    if ($link['status'] !== $supplier->quoteparts_status || ($link['supplier_comment'] ?? null) !== $supplier->quoteparts_comment) {
                        Supplier::whereKey($supplier->id)->update([
                            'quoteparts_status' => $link['status'],
                            'quoteparts_comment' => $link['supplier_comment'] ?? null,
                        ]);
                        $updated++;
                    }
                }
            });

        return $updated;
    }
}
