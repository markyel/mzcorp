<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Роль `claims_manager` — менеджер по рекламациям (2026-10-09).
 *
 * Открывает любую заявку только на чтение: переписка с клиентом и
 * поставщиками, фото и файлы, скачивание вложений — для работы по претензии
 * с поставщиком или клиентом. Действий в заявке нет, почтового клиента нет,
 * на заявки не назначается.
 *
 * Через migration, а не seeder — чтобы роль появилась при первом `migrate`
 * на любом окружении (как add_admin_role).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('roles')
            ->where('name', 'claims_manager')
            ->where('guard_name', 'web')
            ->exists();
        if (! $exists) {
            DB::table('roles')->insert([
                'name' => 'claims_manager',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'claims_manager')
            ->where('guard_name', 'web')
            ->delete();
    }
};
