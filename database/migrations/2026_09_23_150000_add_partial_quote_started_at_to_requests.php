<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Когда по заявке выслали частичное КП.
 *
 * От этой точки отсчитываются две недели, в течение которых система пытается
 * дослать полное предложение: цены на отложенные позиции приходят из 1С, и
 * заявка ждёт их, а не закрывается по молчанию клиента. Дальше двух недель
 * запрос обычно уже неактуален — досылки прекращаются, заявка остаётся
 * менеджеру.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('requests', 'partial_quote_started_at')) {
            return;
        }

        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('partial_quote_started_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('requests', 'partial_quote_started_at')) {
            return;
        }

        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('partial_quote_started_at');
        });
    }
};
