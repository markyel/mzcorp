<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Добавляем роль `admin` (2026-05-21).
 *
 * Технический администратор — видит всё (как директорат), но управлять
 * админами могут только другие админы. Не виден в списках менеджеров
 * для РОПа/директора, не назначается на заявки.
 *
 * Используется для:
 *   · подключение общих ящиков (mailboxes.index)
 *   · активация/деактивация маршрутизации (mailboxes is_active)
 *
 * Через migration, а не seeder — чтобы роль появилась при первом `migrate`
 * на любом окружении.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Spatie hash-cached; обновится сам после следующего ->getRoleNames().
        $exists = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->exists();
        if (! $exists) {
            DB::table('roles')->insert([
                'name' => 'admin',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->delete();
    }
};
