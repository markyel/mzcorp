<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Включает расширение pgvector в БД.
     *
     * Используется для эмбеддингов (KB-матчинг каталога, классификация писем).
     *
     * На Beget Cloud DB пользователь приложения (myliftuser) и даже cloud_user
     * НЕ имеют прав на CREATE EXTENSION — расширение должна включить поддержка
     * Beget в whitelist кластера. Поэтому миграция проверяет наличие vector и
     * пропускается, если оно ещё не активировано (no-op).
     *
     * После того как Beget включит vector в whitelist — расширение появится
     * автоматически или после явного `CREATE EXTENSION vector` от cloud_user.
     */
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 AS present FROM pg_extension WHERE extname = 'vector'"
        );

        if ($exists) {
            return; // Уже включено — ничего не делаем.
        }

        // Пытаемся включить, если есть права. Если нет — не падаем,
        // оставляем как TODO до активации в whitelist Beget.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (\Throwable $e) {
            logger()->warning(
                'pgvector extension is not enabled. Request Beget support to whitelist it. Reason: '
                . $e->getMessage()
            );
        }
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
