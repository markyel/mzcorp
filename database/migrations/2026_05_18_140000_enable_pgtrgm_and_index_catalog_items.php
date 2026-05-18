<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Включает pg_trgm и создаёт GIN-trigram-индексы по catalog_items.name и
     * brand_article_normalized — для гибридного поиска (trigram + vector) в
     * UI «Похожие из каталога».
     *
     * Trigram моментально (~10-50мс) ловит точные/нечёткие текстовые
     * совпадения («Плата ПКЛ32-04» → M16660 в name), что закрывает дыру
     * чистого vector-поиска, который семантически тянет «микроконтроллер
     * платы» выше реальной платы.
     *
     * Tolerant ко всему:
     *  - расширение может быть уже включено (whitelisted Beget) — skip;
     *  - прав на CREATE EXTENSION может не быть — лог + skip;
     *  - таблица может ещё не существовать (свежая БД) — skip;
     *  - индексы могут уже быть — IF NOT EXISTS.
     *
     * Hybrid-сервис fail-soft возвращается к pure-vector если trigram не
     * доступен.
     */
    public function up(): void
    {
        $this->ensureExtension();

        if (! $this->extensionPresent()) {
            // Без pg_trgm индексы не имеют смысла — выходим. Сервис увидит
            // отсутствие расширения и пойдёт по vector-only ветке.
            return;
        }

        if (! $this->tablePresent('catalog_items')) {
            return;
        }

        // GIN-trigram по lower(name) — query тоже лоуэркейсим.
        try {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS catalog_items_name_trgm_idx '
                . 'ON catalog_items USING gin (lower(name) gin_trgm_ops)'
            );
        } catch (\Throwable $e) {
            logger()->warning('pg_trgm index on catalog_items.name failed: ' . $e->getMessage());
        }

        // GIN-trigram по brand_article_normalized (уже uppercase + stripped
        // separators — для query нормализуем тем же CatalogImportService).
        try {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS catalog_items_brand_art_norm_trgm_idx '
                . 'ON catalog_items USING gin (brand_article_normalized gin_trgm_ops)'
            );
        } catch (\Throwable $e) {
            logger()->warning('pg_trgm index on catalog_items.brand_article_normalized failed: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // Индексы дроп — безопасно, расширение не трогаем (могут быть другие пользователи).
        try {
            DB::statement('DROP INDEX IF EXISTS catalog_items_name_trgm_idx');
        } catch (\Throwable) {
            // no-op
        }
        try {
            DB::statement('DROP INDEX IF EXISTS catalog_items_brand_art_norm_trgm_idx');
        } catch (\Throwable) {
            // no-op
        }
    }

    private function ensureExtension(): void
    {
        if ($this->extensionPresent()) {
            return;
        }
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable $e) {
            logger()->warning(
                'pg_trgm extension is not enabled. Request Beget support to whitelist it. Reason: '
                . $e->getMessage()
            );
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

    private function tablePresent(string $name): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable($name);
        } catch (\Throwable) {
            return false;
        }
    }
};
