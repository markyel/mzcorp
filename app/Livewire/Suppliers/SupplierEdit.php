<?php

namespace App\Livewire\Suppliers;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ManufacturerBrand;
use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Models\SupplierOrganization;
use App\Services\Supplier\SupplierMatrixBuilder;
use App\Services\Supplier\SupplierOrganizationService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Карточка поставщика из реестра (Фаза 3.1): реквизиты + описание ассортимента
 * + матрица «бренд/категория» (строит AI) для подбора под позицию. Доступ —
 * все роли (как «Клиенты»/«Поставщики»).
 */
class SupplierEdit extends Component
{
    public Supplier $supplier;

    public string $name = '';
    public string $contact_person = '';
    public string $email = '';
    public string $domain = '';
    public string $phone = '';
    public string $language = 'ru';
    public string $assortment_description = '';
    public string $notes = '';

    /** Ручные правила подбора с wildcard «ВСЕ»: [{brand, category}]. */
    public array $rules = [];

    public string $newRuleBrand = 'ВСЕ';
    public string $newRuleCategory = 'ВСЕ';

    public bool $confirmingDelete = false;

    public const ALL = 'ВСЕ';

    public function mount(Supplier $supplier): void
    {
        abort_unless(auth()->check(), 403);
        $this->supplier = $supplier;
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $s = $this->supplier;
        $this->name = (string) ($s->name ?? '');
        $this->contact_person = (string) ($s->contact_person ?? '');
        $this->email = (string) ($s->email ?? '');
        $this->domain = (string) ($s->domain ?? '');
        $this->phone = (string) ($s->phone ?? '');
        $this->language = in_array($s->language, ['ru', 'en'], true) ? $s->language : 'ru';
        $this->assortment_description = (string) ($s->assortment_description ?? '');
        $this->notes = (string) ($s->notes ?? '');
        $matrix = is_array($s->assortment_matrix) ? $s->assortment_matrix : [];
        $this->rules = array_values(array_filter((array) ($matrix['rules'] ?? []), 'is_array'));
        $this->orgName = (string) ($s->organization?->name ?? '');
    }

    /** @return array<int, string> */
    #[Computed]
    public function brandOptions(): array
    {
        return array_merge([self::ALL], ManufacturerBrand::query()->orderBy('name')->pluck('name')->all());
    }

    /** @return array<int, string> */
    #[Computed]
    public function categoryOptions(): array
    {
        return array_merge([self::ALL], EquipmentCategory::query()->orderBy('name')->pluck('name')->all());
    }

    public function addRule(): void
    {
        $b = trim($this->newRuleBrand) ?: self::ALL;
        $c = trim($this->newRuleCategory) ?: self::ALL;
        if ($b === self::ALL && $c === self::ALL) {
            // {ВСЕ, ВСЕ} допустимо — поставщик-«универсал».
        }
        // Дедуп.
        foreach ($this->rules as $r) {
            if (($r['brand'] ?? '') === $b && ($r['category'] ?? '') === $c) {
                return;
            }
        }
        $this->rules[] = ['brand' => $b, 'category' => $c];
        $this->persistRules();
        $this->newRuleBrand = self::ALL;
        $this->newRuleCategory = self::ALL;
    }

    public function removeRule(int $idx): void
    {
        if (isset($this->rules[$idx])) {
            unset($this->rules[$idx]);
            $this->rules = array_values($this->rules);
            $this->persistRules();
        }
    }

    private function persistRules(): void
    {
        $matrix = is_array($this->supplier->assortment_matrix) ? $this->supplier->assortment_matrix : [];
        $matrix['rules'] = array_values($this->rules);
        $this->supplier->forceFill(['assortment_matrix' => $matrix])->save();
        $this->dispatch('toast', message: 'Правила подбора обновлены.', type: 'success');
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'domain' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:64',
            'assortment_description' => 'nullable|string|max:6000',
            'notes' => 'nullable|string|max:5000',
        ], [], ['email' => 'email', 'domain' => 'домен']);

        $email = mb_strtolower(trim($this->email));
        $domain = ltrim(mb_strtolower(trim($this->domain)), '@');
        if ($email === '' && $domain === '') {
            $this->addError('email', 'Укажите email или домен.');

            return;
        }

        $this->supplier->update([
            'name' => trim($this->name) !== '' ? trim($this->name) : null,
            'contact_person' => trim($this->contact_person) !== '' ? trim($this->contact_person) : null,
            'email' => $email !== '' ? $email : null,
            'domain' => $domain !== '' ? $domain : null,
            'phone' => trim($this->phone) !== '' ? trim($this->phone) : null,
            'language' => in_array($this->language, ['ru', 'en'], true) ? $this->language : 'ru',
            'assortment_description' => trim($this->assortment_description) !== '' ? trim($this->assortment_description) : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);

        $this->dispatch('toast', message: 'Сохранено.', type: 'success');
    }

    public function rebuildMatrix(SupplierMatrixBuilder $builder): void
    {
        // Сохраняем описание перед сборкой, чтобы матрица отражала актуальный текст.
        $this->supplier->update([
            'assortment_description' => trim($this->assortment_description) !== '' ? trim($this->assortment_description) : null,
        ]);

        $ok = $builder->rebuild($this->supplier->fresh());
        $this->supplier->refresh();

        $this->dispatch(
            'toast',
            message: $ok ? 'Матрица ассортимента пересобрана.' : 'Не удалось собрать матрицу (LLM недоступен) — попробуйте позже.',
            type: $ok ? 'success' : 'error',
        );
    }

    /* --- Группы поставщика («Китай», «Европа»…) — сохраняются сразу --- */

    public string $newGroupName = '';

    /** @return list<array{id: int, name: string, member: bool, count: int}> */
    #[Computed]
    public function groupOptions(): array
    {
        $mine = $this->supplier->groups()->pluck('supplier_groups.id')->map(fn ($v) => (int) $v)->all();

        return SupplierGroup::query()->withCount('suppliers')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (SupplierGroup $g) => [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'member' => in_array((int) $g->id, $mine, true),
                'count' => (int) $g->suppliers_count,
            ])->all();
    }

    public function toggleGroup(int $groupId): void
    {
        if (! SupplierGroup::whereKey($groupId)->exists()) {
            return;
        }
        $this->supplier->groups()->toggle([$groupId]);
        unset($this->groupOptions);
    }

    /** Новая группа — сразу с этим поставщиком. */
    public function createGroupAndAdd(): void
    {
        $name = trim($this->newGroupName);
        if ($name === '') {
            return;
        }
        $group = SupplierGroup::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? SupplierGroup::create([
                'name' => mb_substr($name, 0, 100),
                'sort_order' => (int) SupplierGroup::query()->max('sort_order') + 1,
                'created_by_user_id' => auth()->id(),
            ]);
        $this->supplier->groups()->syncWithoutDetaching([$group->id]);
        $this->newGroupName = '';
        unset($this->groupOptions);
    }

    /* --- Организация: несколько адресов одной компании --- */

    public string $orgSearch = '';

    public string $orgName = '';

    /** Другие адреса организации этого поставщика. */
    #[Computed]
    public function organizationMembers()
    {
        $orgId = $this->supplier->supplier_organization_id;

        return $orgId === null
            ? collect()
            : Supplier::query()->where('supplier_organization_id', $orgId)->whereKeyNot($this->supplier->id)
                ->orderBy('email')->get(['id', 'email', 'domain', 'name']);
    }

    /**
     * С кем объединить: по поиску — адреса и организации; без поиска —
     * адреса на том же корпоративном домене, ещё не в этой организации.
     *
     * @return array{suppliers: \Illuminate\Support\Collection, organizations: \Illuminate\Support\Collection}
     */
    #[Computed]
    public function organizationCandidates(): array
    {
        $orgId = $this->supplier->supplier_organization_id;
        $base = Supplier::query()->with('organization:id,name')->whereKeyNot($this->supplier->id)
            ->when($orgId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('supplier_organization_id')->orWhere('supplier_organization_id', '!=', $orgId)));

        $s = trim($this->orgSearch);
        if (mb_strlen($s) >= 2) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';

            return [
                'suppliers' => $base->where(fn ($w) => $w->where('email', 'ilike', $like)
                    ->orWhere('domain', 'ilike', $like)->orWhere('name', 'ilike', $like))
                    ->orderBy('email')->limit(8)->get(),
                'organizations' => SupplierOrganization::query()->where('name', 'ilike', $like)
                    ->when($orgId !== null, fn ($q) => $q->whereKeyNot($orgId))
                    ->withCount('suppliers')->orderBy('name')->limit(5)->get(),
            ];
        }

        $domain = SupplierOrganizationService::domainOf($this->supplier);
        $free = array_map('mb_strtolower', (array) config('services.mail.free_mail_domains', []));
        if ($domain === null || in_array($domain, $free, true)) {
            return ['suppliers' => collect(), 'organizations' => collect()];
        }

        return [
            'suppliers' => $base->get()
                ->filter(fn (Supplier $x) => SupplierOrganizationService::domainOf($x) === $domain)
                ->take(8)->values(),
            'organizations' => collect(),
        ];
    }

    /** Объединить этот адрес с другим (если тот уже в организации — войти в неё). */
    public function mergeWith(int $supplierId, SupplierOrganizationService $service): void
    {
        if ($supplierId === (int) $this->supplier->id || ! Supplier::whereKey($supplierId)->exists()) {
            return;
        }
        $org = $service->merge([$this->supplier->id, $supplierId], null, auth()->id());
        $this->afterOrganizationChange('Объединено в «'.$org?->name.'».');
    }

    public function joinOrganization(int $organizationId, SupplierOrganizationService $service): void
    {
        if ($service->attach($this->supplier, $organizationId)) {
            $this->afterOrganizationChange('Добавлен в организацию.');
        }
    }

    /** Завести организацию из одного этого адреса — остальные добавятся потом. */
    public function createOrganization(SupplierOrganizationService $service): void
    {
        if ($this->supplier->supplier_organization_id !== null) {
            return;
        }
        $service->merge([$this->supplier->id], $this->orgName, auth()->id());
        $this->afterOrganizationChange('Организация создана.');
    }

    public function renameOrganization(SupplierOrganizationService $service): void
    {
        $orgId = $this->supplier->supplier_organization_id;
        if ($orgId !== null && $service->rename((int) $orgId, $this->orgName)) {
            $this->afterOrganizationChange('Организация переименована.');
        }
    }

    public function removeMember(int $supplierId, SupplierOrganizationService $service): void
    {
        $member = Supplier::query()->whereKey($supplierId)
            ->where('supplier_organization_id', $this->supplier->supplier_organization_id)->first();
        if ($member !== null && $this->supplier->supplier_organization_id !== null) {
            $service->detach($member);
            $this->afterOrganizationChange('Адрес выведен из организации.');
        }
    }

    public function leaveOrganization(SupplierOrganizationService $service): void
    {
        $service->detach($this->supplier);
        $this->afterOrganizationChange('Адрес выведен из организации.');
    }

    private function afterOrganizationChange(string $message): void
    {
        $this->supplier->refresh();
        $this->supplier->load('organization');
        $this->orgName = (string) ($this->supplier->organization?->name ?? '');
        $this->orgSearch = '';
        unset($this->organizationMembers, $this->organizationCandidates);
        $this->dispatch('toast', message: $message, type: 'success');
    }

    public function deleteSupplier()
    {
        $orgId = $this->supplier->supplier_organization_id;
        $this->supplier->delete();
        if ($orgId !== null) {
            app(SupplierOrganizationService::class)->dropIfEmpty((int) $orgId);
        }

        return $this->redirectRoute('suppliers.index', ['tab' => 'registry'], navigate: true);
    }

    public function render()
    {
        return view('livewire.suppliers.supplier-edit');
    }
}
