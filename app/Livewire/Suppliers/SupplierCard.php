<?php

namespace App\Livewire\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Models\SupplierOrganization;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\SupplierMirrorService;
use App\Services\Supplier\SupplierKeyService;
use App\Services\Supplier\SupplierMatrixBuilder;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Карточка поставщика — копия того, что о нём знает QuoteParts (решение заказчика 06.11.2026):
 * профиль, условия, ключ (код 1С), контакты по темам, матрица ассортимента, группы. Правка уходит
 * в QuoteParts через API, ответ QuoteParts ложится в копию (SupplierMirrorService::apply).
 */
class SupplierCard extends Component
{
    public const TOPICS = [
        'quoting' => 'Расценка',
        'finance' => 'Финансы',
        'logistics' => 'Логистика',
        'claims' => 'Рекламации и техподдержка',
        'general' => 'Основной контакт',
    ];

    public const INCOTERMS = ['EXW', 'FCA', 'FAS', 'FOB', 'CPT', 'CFR', 'CIF', 'CIP', 'DAP', 'DPU', 'DDP'];

    public SupplierOrganization $org;

    public string $backUrl = '';

    // Профиль
    public string $companyName = '';

    public string $country = '';

    public string $locale = 'ru';

    public string $description = '';

    public string $brands = '';

    public string $notes = '';

    public string $leadDays = '';

    // Условия
    public string $incoterm = '';

    public string $incotermPlace = '';

    public string $currency = '';

    public string $paymentTerms = '';

    public string $validityDays = '';

    public bool $packaging = false;

    /** @var list<array{email:string, name:string, phone:string, topics:list<string>}> */
    public array $contacts = [];

    public string $newEmail = '';

    public string $code = '';

    public string $newGroupName = '';

    public function mount(SupplierOrganization $org): void
    {
        abort_unless(auth()->check(), 403);
        $this->backUrl = \App\Support\BackUrl::from(route('suppliers.index', ['tab' => 'registry']));
        $this->org = $org;
        $this->fillFromCopy();
    }

    private function fillFromCopy(): void
    {
        $p = (array) ($this->org->qp_payload ?? []);
        $this->companyName = (string) ($p['company']['name'] ?? $this->org->name);
        $this->country = (string) ($p['company']['country'] ?? '');
        $this->locale = (string) ($p['company']['locale'] ?? 'ru');
        $this->description = (string) ($p['profile']['assortment_description'] ?? '');
        $this->brands = implode(', ', (array) ($p['profile']['brands'] ?? []));
        $this->notes = (string) ($p['profile']['notes'] ?? $this->org->notes ?? '');
        $this->leadDays = (string) ($p['profile']['default_lead_time_days'] ?? '');
        $t = (array) ($p['terms'] ?? []);
        $this->incoterm = (string) ($t['incoterm'] ?? '');
        $this->incotermPlace = (string) ($t['incoterm_place'] ?? '');
        $this->currency = (string) ($t['currency'] ?? '');
        $this->paymentTerms = (string) ($t['payment_terms'] ?? '');
        $this->validityDays = (string) ($t['offer_validity_days'] ?? '');
        $this->packaging = (bool) ($t['packaging_included'] ?? false);

        $this->contacts = Supplier::query()->where('supplier_organization_id', $this->org->id)->whereNotNull('email')
            ->orderByRaw('contact_position nulls last')->orderBy('id')->get()
            ->map(fn (Supplier $s) => [
                'email' => (string) $s->email,
                'name' => (string) ($s->contact_person ?? ''),
                'phone' => (string) ($s->phone ?? ''),
                'topics' => array_values((array) ($s->contact_topics ?: [])),
            ])->all();
    }

    /** Поставщик сам ведёт карточку и согласованные условия, раз работает в кабинете. */
    #[Computed]
    public function supplierOwnsCard(): bool
    {
        return ($this->org->qp_status ?? null) === 'active';
    }

    #[Computed]
    public function termsLocked(): bool
    {
        return $this->supplierOwnsCard && (bool) (($this->org->qp_payload ?? [])['terms_confirmed'] ?? false);
    }

    public function saveProfile(): void
    {
        $this->validate([
            'companyName' => 'required|string|max:255',
            'country' => 'nullable|string|size:2',
            'locale' => 'required|in:ru,en,zh_CN',
            'description' => 'nullable|string|max:5000',
            'brands' => 'nullable|string|max:3000',
            'notes' => 'nullable|string|max:5000',
            'leadDays' => 'nullable|integer|min:0|max:3650',
        ], [], ['companyName' => 'название', 'country' => 'страна (2 буквы)', 'leadDays' => 'срок поставки']);

        $payload = ['profile' => [
            'assortment_description' => trim($this->description) ?: null,
            'brands' => array_values(array_filter(array_map('trim', explode(',', $this->brands)))),
            'notes' => trim($this->notes) ?: null,
            'default_lead_time_days' => $this->leadDays !== '' ? (int) $this->leadDays : null,
        ]];
        if (! $this->supplierOwnsCard) {
            $payload += ['company_name' => trim($this->companyName), 'country' => $this->country !== '' ? strtoupper($this->country) : null, 'locale' => $this->locale];
        }
        $this->push(fn (QuotePartsClient $qp) => $qp->updateSupplier($this->org->qp_key, $payload), 'Профиль сохранён.');
    }

    public function saveTerms(): void
    {
        $this->validate([
            'incoterm' => 'nullable|in:'.implode(',', self::INCOTERMS),
            'incotermPlace' => 'nullable|string|max:255',
            'currency' => 'nullable|string|size:3',
            'paymentTerms' => 'nullable|string|max:500',
            'validityDays' => 'nullable|integer|min:1|max:3650',
        ], [], ['currency' => 'валюта (3 буквы)', 'validityDays' => 'срок действия цены']);

        $this->push(fn (QuotePartsClient $qp) => $qp->updateSupplier($this->org->qp_key, ['terms' => [
            'incoterm' => $this->incoterm ?: null,
            'incoterm_place' => trim($this->incotermPlace) ?: null,
            'currency' => $this->currency !== '' ? strtoupper($this->currency) : null,
            'payment_terms' => trim($this->paymentTerms) ?: null,
            'offer_validity_days' => $this->validityDays !== '' ? (int) $this->validityDays : null,
            'packaging_included' => $this->packaging,
        ]]), 'Условия сохранены.');
    }

    public function addContact(): void
    {
        $email = mb_strtolower(trim($this->newEmail));
        $this->validate(['newEmail' => 'required|email|max:255'], [], ['newEmail' => 'адрес']);
        if (collect($this->contacts)->contains('email', $email)) {
            $this->addError('newEmail', 'Этот адрес уже в контактах.');

            return;
        }
        $other = Supplier::query()->whereRaw('lower(email) = ?', [$email])->where('supplier_organization_id', '!=', $this->org->id)->with('organization')->first();
        if ($other !== null) {
            $this->addError('newEmail', 'Адрес числится у поставщика «'.($other->organization?->name ?? $other->email).'».');

            return;
        }
        $this->contacts[] = ['email' => $email, 'name' => '', 'phone' => '', 'topics' => ['quoting']];
        $this->newEmail = '';
    }

    public function removeContact(int $i): void
    {
        unset($this->contacts[$i]);
        $this->contacts = array_values($this->contacts);
    }

    public function moveContact(int $i, int $dir): void
    {
        $j = $i + $dir;
        if (isset($this->contacts[$i], $this->contacts[$j])) {
            [$this->contacts[$i], $this->contacts[$j]] = [$this->contacts[$j], $this->contacts[$i]];
        }
    }

    public function saveContacts(): void
    {
        $rows = collect($this->contacts)->filter(fn ($c) => trim($c['email']) !== '')->values();
        if ($rows->isEmpty()) {
            $this->addError('contacts', 'Нужен хотя бы один контакт.');

            return;
        }
        if ($rows->contains(fn ($c) => ($c['topics'] ?? []) === [])) {
            $this->addError('contacts', 'У каждого контакта отметьте хотя бы одну тему.');

            return;
        }
        $keep = $rows->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $ok = $this->push(fn (QuotePartsClient $qp) => $qp->setContacts($this->org->qp_key, [
            'contacts' => $rows->take(30)->map(fn ($c, $i) => array_filter([
                'email' => $c['email'],
                'name' => trim($c['name']) ?: null,
                'phone' => trim($c['phone']) ?: null,
                'topics' => array_values($c['topics']),
                'position' => $i + 1,
            ], fn ($v) => $v !== null))->all(),
        ]), 'Контакты сохранены.');
        if ($ok) {
            // Удалённые из контактов адреса — больше не адреса этого поставщика.
            Supplier::query()->where('supplier_organization_id', $this->org->id)->whereNotNull('email')
                ->whereNotIn(\Illuminate\Support\Facades\DB::raw('lower(email)'), $keep)->delete();
            $this->fillFromCopy();
        }
    }

    public function setCode(string $code): void
    {
        try {
            $this->org = app(SupplierKeyService::class)->setOnecCode($this->org, $code);
            $this->dispatch('toast', message: 'Код 1С: '.$this->org->onec_code.'.', type: 'success');
            $this->code = '';
        } catch (Throwable $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public string $mergeQuery = '';

    /** Присоединить другого поставщика (код 1С, ключ или название) к этому. */
    public function mergeIn(): void
    {
        $q = trim($this->mergeQuery);
        $found = $q === '' ? collect() : SupplierOrganization::query()->whereKeyNot($this->org->id)
            ->where(fn ($w) => $w->where('onec_code', $q)->orWhere('qp_key', $q)->orWhere('name', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%'))
            ->limit(2)->get();
        if ($found->count() !== 1) {
            $this->dispatch('toast', message: $found->isEmpty() ? 'Поставщик не найден.' : 'Подходят несколько поставщиков — уточните код 1С.', type: 'error');

            return;
        }
        try {
            $this->org = app(\App\Services\Supplier\SupplierMergeService::class)->merge($this->org, $found->first());
            $this->mergeQuery = '';
            $this->fillFromCopy();
            unset($this->groupOptions, $this->supplierOwnsCard, $this->termsLocked);
            $this->dispatch('toast', message: 'Объединено: «'.$found->first()->name.'» теперь часть этого поставщика.', type: 'success');
        } catch (Throwable $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function setManualCode(): void
    {
        $this->setCode($this->code);
    }

    public function toggleAgent(): void
    {
        $agent = ! (bool) (($this->org->qp_payload ?? [])['is_agent'] ?? false);
        $this->push(fn (QuotePartsClient $qp) => $qp->setAgent($this->org->qp_key, $agent), $agent ? 'Отмечен как агент.' : 'Больше не агент.');
    }

    public function rebuildMatrix(SupplierMatrixBuilder $builder): void
    {
        $rules = (array) (((array) (($this->org->qp_payload ?? [])['assortment_matrix'] ?? []))['rules'] ?? []);
        $matrix = $builder->compute(trim($this->description), $rules);
        if ($matrix === null) {
            $this->dispatch('toast', message: 'Модель не ответила — матрица не собрана, попробуйте позже.', type: 'error');

            return;
        }
        $this->push(fn (QuotePartsClient $qp) => $qp->setSupplierMatrix($this->org->qp_key, $matrix), 'Матрица ассортимента собрана по описанию.');
    }

    /** @return list<array{id:int, name:string, member:bool}> */
    #[Computed]
    public function groupOptions(): array
    {
        $ids = Supplier::query()->where('supplier_organization_id', $this->org->id)->pluck('id');
        $mine = \Illuminate\Support\Facades\DB::table('supplier_group_members')->whereIn('supplier_id', $ids)->distinct()->pluck('supplier_group_id')->map(fn ($v) => (int) $v)->all();

        return SupplierGroup::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name, 'member' => in_array((int) $g->id, $mine, true)])->all();
    }

    public function toggleGroup(int $groupId): void
    {
        $group = SupplierGroup::query()->findOrFail($groupId);
        $ids = Supplier::query()->where('supplier_organization_id', $this->org->id)->pluck('id')->all();
        $member = collect($this->groupOptions)->firstWhere('id', $groupId)['member'] ?? false;
        $member ? $group->suppliers()->detach($ids) : $group->suppliers()->syncWithoutDetaching($ids);
        unset($this->groupOptions);
    }

    public function createGroupAndAdd(): void
    {
        $name = trim($this->newGroupName);
        if ($name === '') {
            return;
        }
        $group = SupplierGroup::query()->firstOrCreate(['name' => mb_substr($name, 0, 100)]);
        $group->suppliers()->syncWithoutDetaching(Supplier::query()->where('supplier_organization_id', $this->org->id)->pluck('id')->all());
        $this->newGroupName = '';
        unset($this->groupOptions);
    }

    public string $archiveReason = '';

    /** В архив: не виден в реестре, не предлагается в запросах цен; в QuoteParts — тоже (если связь там есть). */
    public function archive(\App\Services\Supplier\SupplierArchiveService $archive): void
    {
        $this->validate(['archiveReason' => 'nullable|string|max:500'], [], ['archiveReason' => 'причина']);
        try {
            $this->org = $archive->archive($this->org, $this->archiveReason, auth()->user());
            $this->archiveReason = '';
            $this->dispatch('toast', message: '«'.$this->org->name.'» в архиве.', type: 'success');
        } catch (Throwable $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function restoreFromArchive(\App\Services\Supplier\SupplierArchiveService $archive): void
    {
        try {
            $this->org = $archive->restore($this->org, auth()->user());
            $this->dispatch('toast', message: '«'.$this->org->name.'» снова в работе.', type: 'success');
        } catch (Throwable $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    /** Правка в QuoteParts → ответ в копию. */
    private function push(callable $call, string $done): bool
    {
        try {
            $link = $call(app(QuotePartsClient::class));
            app(SupplierMirrorService::class)->apply((array) $link);
            $this->org->refresh();
            unset($this->supplierOwnsCard, $this->termsLocked);
            $this->dispatch('toast', message: $done, type: 'success');

            return true;
        } catch (Throwable $e) {
            $this->dispatch('toast', message: 'QuoteParts: '.$e->getMessage(), type: 'error');

            return false;
        }
    }

    public function render()
    {
        return view('livewire.suppliers.supplier-card');
    }
}
