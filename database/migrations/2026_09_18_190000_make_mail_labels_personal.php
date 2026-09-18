<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Метки становятся личными: у каждого менеджера свой набор.
 *
 * Изначально словарь был общим на компанию — заказчик решил иначе: «У каждого
 * менеджера должен быть свой набор меток». Метка — это личный способ разложить
 * свою почту, а не общая таксономия, и чужие метки в списке только мешают.
 *
 * Имена уникальны теперь В ПРЕДЕЛАХ владельца: «Срочно» может быть у каждого.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_labels')) {
            return;
        }

        if (! Schema::hasColumn('mail_labels', 'owner_user_id')) {
            Schema::table('mail_labels', function (Blueprint $t) {
                $t->foreignId('owner_user_id')->nullable()->after('id')
                    ->constrained('users')->cascadeOnDelete();
            });

            // Существующие метки достаются тому, кто их завёл.
            DB::statement('UPDATE mail_labels SET owner_user_id = created_by_user_id WHERE owner_user_id IS NULL');
            // Метки без автора (теоретически — из ручных вставок) владельца не
            // получат, а значит и смысла не имеют: удаляем вместе с привязками.
            DB::table('mail_labels')->whereNull('owner_user_id')->delete();

            DB::statement('ALTER TABLE mail_labels ALTER COLUMN owner_user_id SET NOT NULL');
        }

        // Уникальность имени — в пределах владельца.
        DB::statement('DROP INDEX IF EXISTS mail_labels_name_lower_idx');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS mail_labels_owner_name_lower_idx
             ON mail_labels (owner_user_id, lower(name))'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('mail_labels')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS mail_labels_owner_name_lower_idx');
        if (Schema::hasColumn('mail_labels', 'owner_user_id')) {
            Schema::table('mail_labels', function (Blueprint $t) {
                $t->dropConstrainedForeignId('owner_user_id');
            });
        }
        // Восстановить глобальную уникальность можно только если дублей нет —
        // иначе индекс не создастся, и это правильно: разбирать дубли руками.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS mail_labels_name_lower_idx ON mail_labels (lower(name))'
        );
    }
};
