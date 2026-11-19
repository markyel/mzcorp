<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Календарь в подарок»: бот больше не спрашивает адрес — адрес и способ передачи согласуем с получателем отдельно
 * (19.11.2026). Поле остаётся для записи после согласования.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('calendar_gift_recipients', 'address')) {
            Schema::table('calendar_gift_recipients', function (Blueprint $table) {
                $table->text('address')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Обратно NOT NULL — только если пустых адресов нет: иначе миграция упадёт на записях из бота.
        if (Schema::hasColumn('calendar_gift_recipients', 'address')
            && \Illuminate\Support\Facades\DB::table('calendar_gift_recipients')->whereNull('address')->doesntExist()) {
            Schema::table('calendar_gift_recipients', function (Blueprint $table) {
                $table->text('address')->nullable(false)->change();
            });
        }
    }
};
