<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Запись реестра поставщиков (модуль поставщиков): email и/или домен,
 * который мы считаем поставщиком. См. миграцию create_suppliers_table и
 * App\Services\Supplier\SupplierRegistry.
 *
 * @property ?string $email
 * @property ?string $domain
 * @property ?string $name
 * @property ?string $contact_person
 * @property ?string $notes
 * @property ?int $created_by_user_id
 */
class Supplier extends Model
{
    protected $fillable = [
        'email',
        'domain',
        'name',
        // Контактное лицо — подставляется в обращение письма вместо названия.
        'contact_person',
        'phone',
        // Язык общения (ru|en) — письмо-запрос и номенклатура на этом языке.
        'language',
        'notes',
        // Фаза 3.1 — профиль для подбора под позицию.
        'assortment_description',
        'assortment_matrix',
        'matrix_built_at',
        'matrix_built_with_model',
        'created_by_user_id',
        // Организация: несколько адресов одной компании (SupplierOrganizationService).
        'supplier_organization_id',
    ];

    protected function casts(): array
    {
        return [
            'assortment_matrix' => 'array',
            'matrix_built_at' => 'datetime',
            'quoteparts_invited_at' => 'datetime',
            'quoteparts_groups' => 'array',
            'quoteparts_offsystem' => 'boolean',
            // Агент (UniSystem): ищет поставщиков для нас, в каждой его цене — реальный поставщик.
            'quoteparts_agent' => 'boolean',
            // Темы контакта в QuoteParts: quoting, finance, logistics, claims, general.
            'contact_topics' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Организация, в которую объединён этот адрес с другими адресами той же компании. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(SupplierOrganization::class, 'supplier_organization_id');
    }

    /**
     * Адреса поставщиков не из архива — для подбора, групп и поиска в запросах цен.
     * Архивируется поставщик (организация) целиком.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Supplier>  $query
     */
    public function scopeNotArchived($query): void
    {
        $query->where(fn ($q) => $q->whereNull($this->qualifyColumn('supplier_organization_id'))
            ->orWhereNotIn($this->qualifyColumn('supplier_organization_id'), SupplierOrganization::query()->select('id')->whereNotNull('archived_at')));
    }

    /** Группы («Китай», «Европа», «Поручни»…) — выбор поставщиков для запроса цены группой. */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(SupplierGroup::class, 'supplier_group_members')->withTimestamps();
    }

    /**
     * Имя для обращения в письме («Здравствуйте, …!»): контактное лицо,
     * иначе название компании, иначе null (вызывающий подставит «коллеги»).
     */
    public function greetingName(): ?string
    {
        $v = trim((string) ($this->contact_person ?: $this->name ?: ''));

        return $v !== '' ? $v : null;
    }

    /**
     * Ключ поставщика в QuoteParts: организация (несколько адресов одной фирмы — одна
     * компания-поставщик там), а адрес без организации — сам по себе.
     */
    public function quotepartsKey(): string
    {
        // Постоянный ключ организации (код 1С или «mz:{id}»); прежняя схема — пока ключ не проставлен.
        // Загруженной организации верим, только если в ней есть qp_key: связь, загруженная как
        // organization:id,name (QuotePartsRfqService и ещё десяток мест), давала «org:9» вместо
        // «00-000523», и QuoteParts отвечал «Поставщик org:9 не приглашён» (06.11–09.11.2026:
        // принявшим приглашение запрос не уходил вовсе).
        $key = null;
        if ($this->supplier_organization_id) {
            $org = $this->relationLoaded('organization') ? $this->organization : null;
            $key = $org !== null && array_key_exists('qp_key', $org->getAttributes())
                ? $org->qp_key
                : ($this->exists ? SupplierOrganization::query()->whereKey($this->supplier_organization_id)->value('qp_key') : null);
        }

        return $key ?: ($this->supplier_organization_id ? 'org:'.$this->supplier_organization_id : 'sup:'.$this->id);
    }

    /**
     * Адреса той же фирмы (организации) — кандидаты в контакты поставщика в QuoteParts.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public function quotepartsColleagues(): \Illuminate\Support\Collection
    {
        return $this->supplier_organization_id
            ? self::query()->where('supplier_organization_id', $this->supplier_organization_id)->whereNotNull('email')->orderBy('id')->get()
            : collect([$this]);
    }
}
