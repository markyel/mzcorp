<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Расширяет enum `request_items.quality_assessment_status` значением
 * `internal_catalog_not_found` (Priority 1 ручного управления позициями).
 *
 * Семантика: внутренний M-SKU не найден в каталоге, оператор подтвердил
 * вручную, что SKU не появится (опечатка, устаревший код). Отличается от
 * `internal_catalog_pending`, который означает «ждём импорт каталога».
 *
 * `catalog:import` через ResolvePendingFromCatalogJob трогает только
 * `internal_catalog_pending`, поэтому `not_found` останется final-статусом
 * пока оператор сам не отменит решение (unbind → refresh).
 *
 * Шаблон взят из 2026_05_08_210001_add_internal_catalog_pending_*.
 */
return new class extends Migration
{
    private const VALUES_NEW = [
        'not_assessed',
        'sufficient',
        'insufficient',
        'not_covered',
        'assessment_failed',
        'internal_catalog_pending',
        'internal_catalog_not_found',
    ];

    private const VALUES_OLD = [
        'not_assessed',
        'sufficient',
        'insufficient',
        'not_covered',
        'assessment_failed',
        'internal_catalog_pending',
    ];

    public function up(): void
    {
        $this->replaceCheck(self::VALUES_NEW);
    }

    public function down(): void
    {
        // Перед откатом — нормализуем not_found → pending, чтобы CHECK
        // прошёл. Это безопаснее чем not_assessed: семантика «ещё не
        // найден в каталоге» ближе всего к pending.
        DB::table('request_items')
            ->where('quality_assessment_status', 'internal_catalog_not_found')
            ->update(['quality_assessment_status' => 'internal_catalog_pending']);

        $this->replaceCheck(self::VALUES_OLD);
    }

    private function replaceCheck(array $values): void
    {
        $constraint = 'request_items_quality_assessment_status_check';
        DB::statement("ALTER TABLE request_items DROP CONSTRAINT IF EXISTS {$constraint}");

        $list = "'" . implode("','", $values) . "'";
        DB::statement(
            "ALTER TABLE request_items ADD CONSTRAINT {$constraint} "
            . "CHECK (quality_assessment_status::text = ANY (ARRAY[{$list}]::text[]))"
        );
    }
};
