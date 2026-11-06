<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Models\SupplierContactCandidate;
use App\Models\SupplierOrganization;
use App\Models\User;

/**
 * Реестр поставщиков (модуль поставщиков). Первый гейт распознавания нашего
 * исходящего как запроса расценки: получатель должен быть в реестре (по email
 * или по домену). Окончательное решение — за LLM (SupplierRfqClassifier),
 * т.к. контрагент бывает и клиентом.
 */
class SupplierRegistry
{
    /** Является ли email поставщиком: точное совпадение email ИЛИ домена. */
    public function isSupplier(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }
        $domain = substr($email, strpos($email, '@') + 1);

        return Supplier::query()
            ->where(function ($q) use ($email, $domain) {
                $q->whereRaw('lower(email) = ?', [$email])
                    ->orWhereRaw('lower(domain) = ?', [$domain]);
            })
            ->exists()
            // Претендент, которого ещё не разобрали, — тоже поставщик для распознавания писем.
            || SupplierContactCandidate::query()->where('email', $email)->where('status', SupplierContactCandidate::PENDING)->exists();
    }

    /**
     * Адрес из переписки с поставщиком (наш запрос цены, помеченный тред, закрытая заявка). С
     * 06.11.2026 поставщики живут в QuoteParts, и новый адрес не заводится сам — он становится
     * претендентом, которого подтверждает человек (раздел «Поставщики» → «Реестр»). Известный
     * адрес возвращается как есть.
     */
    public function registerEmail(string $email, ?string $name = null, ?User $by = null, string $source = 'mail', ?string $context = null): ?Supplier
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $supplier = Supplier::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($supplier !== null) {
            return $supplier;
        }

        $candidate = SupplierContactCandidate::query()->firstOrNew(['email' => $email]);
        if (! $candidate->exists) {
            $candidate->fill([
                'name' => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null,
                'source' => array_key_exists($source, SupplierContactCandidate::SOURCES) ? $source : 'mail',
                'context' => $context !== null ? mb_substr($context, 0, 255) : null,
                'suggested_organization_id' => $this->organizationByDomain($email),
                'status' => SupplierContactCandidate::PENDING,
                'created_by_user_id' => $by?->id,
            ])->save();
        }

        return null;
    }

    /** Поставщик с адресами на том же корпоративном домене — подсказка, чей это контакт. */
    public function organizationByDomain(string $email): ?int
    {
        $domain = mb_strtolower(substr($email, strpos($email, '@') + 1));
        if (in_array($domain, (array) config('services.mail.free_mail_domains', []), true)) {
            return null;
        }
        $orgs = Supplier::query()->whereNotNull('supplier_organization_id')
            ->where(fn ($q) => $q->whereRaw('lower(domain) = ?', [$domain])->orWhereRaw('lower(email) like ?', ['%@'.$domain]))
            ->distinct()->pluck('supplier_organization_id');

        return $orgs->count() === 1 ? (int) $orgs->first() : null;
    }

    /**
     * Решение по претенденту: контакт поставщика $orgId, новый поставщик ($orgId = null) или
     * не поставщик ($reject). Контакт сразу уходит в QuoteParts вместе с остальными адресами.
     */
    public function decide(SupplierContactCandidate $candidate, ?int $orgId, ?User $by, bool $reject = false): ?SupplierOrganization
    {
        if ($reject) {
            $candidate->forceFill(['status' => SupplierContactCandidate::REJECTED, 'decided_by_user_id' => $by?->id, 'decided_at' => now()])->save();

            return null;
        }

        $org = $orgId !== null ? SupplierOrganization::query()->findOrFail($orgId) : null;
        if ($org === null) {
            $org = SupplierOrganization::create([
                'name' => $candidate->name ?: substr($candidate->email, strpos($candidate->email, '@') + 1),
                'created_by_user_id' => $by?->id,
            ]);
            $org->forceFill(['qp_key' => SupplierOrganization::temporaryKey($org->id)])->save();
        }
        if (Supplier::query()->whereRaw('lower(email) = ?', [$candidate->email])->doesntExist()) {
            Supplier::create([
                'email' => $candidate->email,
                'domain' => substr($candidate->email, strpos($candidate->email, '@') + 1),
                'contact_person' => $candidate->name,
                'supplier_organization_id' => $org->id,
                'created_by_user_id' => $by?->id,
            ])->forceFill(['source' => 'candidate', 'contact_topics' => ['quoting']])->save();
        }
        $candidate->forceFill(['status' => SupplierContactCandidate::ACCEPTED, 'suggested_organization_id' => $org->id,
            'decided_by_user_id' => $by?->id, 'decided_at' => now()])->save();

        app(\App\Services\QuoteParts\QuotePartsSupplierService::class)->registerOrganization($org);

        return $org;
    }
}
