<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
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
            ->exists();
    }

    /**
     * Зарегистрировать поставщика по email (идемпотентно). Используется как
     * bootstrap при ручной пометке треда (markFromRequest) и из UI.
     */
    public function registerEmail(string $email, ?string $name = null, ?User $by = null): ?Supplier
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $supplier = Supplier::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($supplier !== null) {
            return $supplier;
        }

        return Supplier::create([
            'email' => $email,
            'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
            'created_by_user_id' => $by?->id,
        ]);
    }
}
