<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Функциональный GIN-trigram-индекс по дефис/пробел-очищенному
     * lower(name) — для случаев когда менеджер вводит запрос с дефисом
     * (например, «Плата ПКЛ-32»), а в каталоге написано «ПКЛ32-04».
     *
     * Без этого индекса слот dehyphenated в trigramTopN скатывается в
     * seq scan — это не критично для одного запроса, но добавление
     * индекса делает hybrid-поиск стабильно <50мс.
     *
     * pg_trgm должен быть включён предыдущей миграцией. Если нет —
     * tolerant skip (как с pg_trgm enable).
     */
    public function up(): void
    {
        if (! $this->extensionPresent()) {
            return;
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('catalog_items')) {
            return;
        }

        try {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS catalog_items_name_nosep_trgm_idx "
                . "ON catalog_items USING gin (regexp_replace(lower(name), '[\\s\\-_./]', '', 'g') gin_trgm_ops)"
            );
        } catch (\Throwable $e) {
            logger()->warning(
                'dehyphenated name trgm index failed: ' . $e->getMessage()
            );
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS catalog_items_name_nosep_trgm_idx');
        } catch (\Throwable) {
            // no-op
        }
    }

    private function extensionPresent(): bool
    {
        try {
            $row = DB::selectOne(
                "SELECT 1 AS present FROM pg_extension WHERE extname = 'pg_trgm'"
            );
            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }
};
