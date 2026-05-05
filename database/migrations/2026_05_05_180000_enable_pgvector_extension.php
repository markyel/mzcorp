<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Включает расширение pgvector в БД.
     *
     * Используется для эмбеддингов (KB-матчинг каталога, классификация писем).
     * Требует, чтобы у пользователя БД были права на CREATE EXTENSION,
     * либо чтобы расширение уже было создано суперюзером заранее.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    /**
     * Откат намеренно не дропает расширение —
     * это потенциально уничтожило бы данные в других зависящих таблицах.
     * Если нужно удалить — делается вручную DBA.
     */
    public function down(): void
    {
        // no-op
    }
};
