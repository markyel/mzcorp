<?php

namespace App\Services\Kb;

/**
 * MyLift stub (Phase 2.0): supplier infra (`App\Models\Supplier`, `suppliers`
 * table) ещё не добавлена в проект. RequestContextAnalysisService требует
 * этот сервис в DI — оставляем no-op API, всегда возвращающий null. Когда
 * supplier model появится (Phase 2.5+) — заменим на полноценную копию из
 * LazyLift `app/Services/Kb/SupplierResolverService.php`.
 */
class SupplierResolverService
{
    /**
     * Stub: реальный резолв требует таблицы suppliers, которой пока нет.
     *
     * @param array{type: string, value?: string|null, supplier_name?: string|null} $mention
     */
    public function resolve(array $mention): ?int
    {
        return null;
    }
}
