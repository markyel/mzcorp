<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аватарки пользователя — до 3 вариантов: нейтральный (список/карточка),
 * победитель и проигравший (в карточке по статусу закрытия заявки).
 * Хранится относительный путь файла на диске `local`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'avatar_neutral_path')) {
                $table->string('avatar_neutral_path')->nullable();
            }
            if (! Schema::hasColumn('users', 'avatar_won_path')) {
                $table->string('avatar_won_path')->nullable();
            }
            if (! Schema::hasColumn('users', 'avatar_lost_path')) {
                $table->string('avatar_lost_path')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['avatar_neutral_path', 'avatar_won_path', 'avatar_lost_path'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
