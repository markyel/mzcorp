<?php

namespace App\Livewire\Suppliers;

use App\Models\Supplier;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsSupplierService;
use Livewire\Component;
use Throwable;

/**
 * Блок «QuoteParts» в карточке поставщика: пригласить в QuoteParts с режимом цены
 * и видеть ответ поставщика. На время тестирования — директорат и админ.
 */
class QuotePartsInvite extends Component
{
    public const INCOTERMS = ['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    public const CURRENCIES = ['USD', 'EUR', 'CNY', 'RUB'];

    public Supplier $supplier;

    public bool $open = false;

    public string $company_name = '';

    public string $contact_name = '';

    public string $email = '';

    public string $locale = 'en';

    public string $country = '';

    public string $incoterm = '';

    public string $incoterm_place = '';

    public string $currency = 'USD';

    public string $payment_terms = '';

    /** '' — не указано, '1' — включена в цену, '0' — не включена. */
    public string $packaging = '';

    public ?int $offer_validity_days = 90;

    public string $message = '';

    /** Показывать поставщику наш M-артикул (по умолчанию — нет). */
    public bool $show_internal_code = false;

    /** @var list<string> группы товаров, которые поставщик видит (пусто — все) */
    public array $groups = [];

    public ?string $error = null;

    public function mount(Supplier $supplier): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['director', 'admin']), 403);

        $this->supplier = $supplier;
        $this->company_name = (string) ($supplier->organization?->name ?: $supplier->name ?: $supplier->domain);
        $this->contact_name = (string) $supplier->contact_person;
        $this->email = (string) $supplier->email;
        // Язык кабинета — как язык переписки с поставщиком: русский для своих, английский для остальных.
        $this->locale = $supplier->language === 'ru' ? 'ru' : 'en';
        // Видит то, что закупаем в его регионе (по «Закупкам 1С»); уже настроенное — как есть.
        $region = $supplier->quoteparts_groups === null ? app(\App\Services\Catalog\SupplyGroupService::class)->regionOf($supplier) : null;
        $this->groups = $supplier->quoteparts_groups ?? ($supplier->quoteparts_link_id === null && $region ? [$region] : []);
    }

    /** Группы товаров уже приглашённого поставщика. */
    /**
     * Внесистемный поставщик: в QuoteParts не приглашаем, но запросы цен идут через платформу —
     * нашим письмом с ящика orders@, ответ разбирает QuoteParts, копия ответа — автору запроса.
     */
    public function toggleOffsystem(): void
    {
        $this->supplier->forceFill(['quoteparts_offsystem' => ! $this->supplier->quoteparts_offsystem])->save();
        \Illuminate\Support\Facades\Log::info('Supplier off-system flag changed', [
            'supplier_id' => $this->supplier->id, 'offsystem' => $this->supplier->quoteparts_offsystem, 'user_id' => auth()->id(),
        ]);
    }

    public function saveGroups(QuotePartsSupplierService $service): void
    {
        $groups = array_values(array_intersect(\App\Models\SupplyGroup::codes(), $this->groups));
        try {
            $service->setGroups($this->supplier, $groups === [] ? null : $groups);
        } catch (Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 400);

            return;
        }
        $this->dispatch('toast', type: 'success', message: $groups === [] ? 'Поставщик видит все группы товаров.' : 'Группы сохранены: поставщик видит '.collect($groups)->map(fn ($g) => \App\Models\SupplyGroup::label($g))->implode(', ').'.');
    }

    public function invite(QuotePartsSupplierService $service): void
    {
        $data = $this->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'locale' => ['required', 'in:en,zh_CN,ru'],
            'country' => ['nullable', 'string', 'size:2'],
            'incoterm' => ['nullable', 'in:'.implode(',', self::INCOTERMS)],
            'incoterm_place' => ['nullable', 'string', 'max:255', 'required_with:incoterm'],
            'currency' => ['nullable', 'in:'.implode(',', self::CURRENCIES)],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'packaging' => ['nullable', 'in:,0,1'],
            'offer_validity_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'message' => ['nullable', 'string', 'max:2000'],
            'show_internal_code' => ['boolean'],
        ], [], [
            'company_name' => 'компания', 'email' => 'почта', 'incoterm_place' => 'место',
            'country' => 'страна', 'offer_validity_days' => 'срок действия',
        ]);

        $data['packaging_included'] = $this->packaging === '' ? null : $this->packaging === '1';
        $groups = array_values(array_intersect(\App\Models\SupplyGroup::codes(), $this->groups));
        $data['groups'] = $groups === [] ? null : $groups;
        $this->error = null;

        try {
            $service->invite($this->supplier, $data);
        } catch (Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 400);

            return;
        }

        $this->supplier->refresh();
        $this->open = false;
        $this->dispatch('toast', type: 'success', message: 'Приглашение отправлено: '.$this->email);
    }

    /* ---------------- Контакты поставщика и порядок рассылки ---------------- */

    public const TOPICS = [
        'quoting' => 'Расценка',
        'finance' => 'Финансы',
        'logistics' => 'Логистика',
        'claims' => 'Рекламации',
        'general' => 'Основной',
    ];

    public bool $contactsOpen = false;

    /** @var array<int, array{email:string, name:string, position:string, topics:array<string, bool>}> */
    public array $contactRows = [];

    /** @var list<array{topic:string, email:?string, name:?string, position:int}> как поставщик сам распределил людей (его страница «Команда») */
    public array $supplierOwnContacts = [];

    public string $dispatchMode = 'all';

    public int $escalateAfterHours = 48;

    public ?string $contactsError = null;

    public function openContacts(QuotePartsSupplierService $service): void
    {
        $this->contactsError = null;
        try {
            $link = $service->link($this->supplier) ?? [];
        } catch (Throwable $e) {
            $this->contactsError = 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200);

            return;
        }

        $current = collect($link['contacts'] ?? [])->keyBy(fn ($c) => mb_strtolower($c['email']));
        $this->dispatchMode = $link['dispatch_mode'] ?? 'all';
        $this->escalateAfterHours = (int) ($link['escalate_after_hours'] ?? 48);
        $this->supplierOwnContacts = array_values((array) ($link['supplier_contacts'] ?? []));

        // Адреса фирмы из справочника + контакты, которые уже есть в QuoteParts.
        $emails = $this->supplier->quotepartsColleagues()->mapWithKeys(fn ($s) => [mb_strtolower($s->email) => $s->contact_person ?: $s->name ?: ''])
            ->union($current->map(fn ($c) => $c['name'] ?? ''));

        $this->contactRows = [];
        $n = 1;
        foreach ($emails as $email => $name) {
            $c = $current->get($email);
            $topics = [];
            foreach (array_keys(self::TOPICS) as $topic) {
                $topics[$topic] = in_array($topic, $c['topics'] ?? [], true);
            }
            // Язык человека: из QuoteParts, а для нового — из справочника (ru / en).
            $known = $this->supplier->quotepartsColleagues()->first(fn ($s) => mb_strtolower((string) $s->email) === $email);
            $this->contactRows[] = [
                'email' => $email,
                'name' => (string) ($c['name'] ?? $name),
                'locale' => (string) ($c['locale'] ?? (($known?->language ?? $this->supplier->language) === 'ru' ? 'ru' : 'en')),
                'position' => (string) ($c['position'] ?? $n),
                'topics' => $topics,
            ];
            $n++;
        }

        $this->contactsOpen = true;
    }

    public function saveContacts(QuotePartsSupplierService $service): void
    {
        $this->validate([
            'dispatchMode' => ['required', 'in:all,sequential'],
            'escalateAfterHours' => ['required', 'integer', 'min:1', 'max:720'],
            'contactRows.*.position' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [], ['escalateAfterHours' => 'часы до следующего', 'contactRows.*.position' => 'порядок']);

        $contacts = [];
        foreach ($this->contactRows as $row) {
            $topics = array_keys(array_filter($row['topics']));
            if ($topics === []) {
                continue;
            }
            $contacts[] = [
                'email' => $row['email'],
                'name' => trim($row['name']) ?: null,
                'topics' => $topics,
                'position' => (int) ($row['position'] ?: 99),
                'locale' => in_array($row['locale'] ?? null, ['ru', 'en', 'zh_CN'], true) ? $row['locale'] : null,
            ];
        }

        if (! collect($contacts)->contains(fn ($c) => in_array('quoting', $c['topics'], true) || in_array('general', $c['topics'], true))) {
            $this->contactsError = 'Нужен хотя бы один контакт по расценке или основной — иначе запросы некому отправлять.';

            return;
        }

        try {
            $service->saveContacts($this->supplier, $contacts, $this->dispatchMode, $this->escalateAfterHours);
        } catch (Throwable $e) {
            $this->contactsError = mb_substr($e->getMessage(), 0, 400);

            return;
        }

        // Тот же язык — у адреса в справочнике: на нём пишет и прямой запрос из mzCorp (ru / en).
        foreach ($contacts as $contact) {
            if ($contact['locale'] !== null) {
                \App\Models\Supplier::query()->whereRaw('lower(email) = ?', [mb_strtolower($contact['email'])])
                    ->update(['language' => $contact['locale'] === 'ru' ? 'ru' : 'en']);
            }
        }

        $this->contactsOpen = false;
        $this->contactsError = null;
        $this->dispatch('toast', type: 'success', message: 'Контакты поставщика в QuoteParts сохранены.');
    }

    public function refreshStatus(QuotePartsSupplierService $service): void
    {
        try {
            $service->refreshStatuses();
        } catch (Throwable) {
        }
        $this->supplier->refresh();
    }

    public function render()
    {
        return view('livewire.suppliers.quote-parts-invite', [
            'configured' => app(QuotePartsClient::class)->isConfigured(),
            'labels' => QuotePartsSupplierService::STATUS_LABELS,
        ]);
    }
}
