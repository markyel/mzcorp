<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Расширяет enum `request_items.quality_assessment_status` новым значением
 * `internal_catalog_pending` (Phase 2.0+).
 *
 * Семантика: позиции с article формата «M\d{4,}» — это внутренние MyLift-SKU.
 * Их категорию/бренд должна давать корпоративная база, а не KB-LLM-цепочка.
 * До появления каталога такие позиции помечаются этим статусом и не уходят
 * в дорогой LLM-резолв.
 *
 * PostgreSQL не имеет ALTER TYPE для CHECK-based enum — Laravel `$table->enum()`
 * на Postgres создаёт VARCHAR + CHECK CONSTRAINT с автоименем
 * `<table>_<column>_check`. Меняем через DROP/ADD constraint.
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
    ];

    private const VALUES_OLD = [
        'not_assessed',
        'sufficient',
        'insufficient',
        'not_covered',
        'assessment_failed',
    ];

    public function up(): void
    {
        $this->replaceCheck(self::VALUES_NEW);
    }

    public function down(): void
    {
        // Перед откатом — нормализуем строки которые заюзают новое значение,
        // чтобы CHECK не упал при создании.
        DB::table('request_items')
            ->where('quality_assessment_status', 'internal_catalog_pending')
            ->update(['quality_assessment_status' => 'not_assessed']);

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
