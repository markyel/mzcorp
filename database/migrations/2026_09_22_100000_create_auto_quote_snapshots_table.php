<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снимок решения авто-КП на момент прихода заявки.
 *
 * Страница раздела считала вердикт на лету, и это давало ложную картину: через
 * неделю у позиции другая цена, у клиента другая скидка, а правило мы успели
 * поправить трижды — и «что автомат выдал бы» пересчитывалось задним числом.
 * Сравнивать с документом менеджера нужно то предложение, которое автомат
 * составил БЫ ТОГДА.
 *
 * Снимок пишется по всем заявкам, а не только по пригодным: отказ правила —
 * тоже решение, и по нему видно, как менялась воронка.
 *
 * Когда выдача заработает вживую, этот же снимок станет тем, что уходит
 * клиенту: сначала фиксируем решение, потом отправляем.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auto_quote_snapshots')) {
            return;
        }

        Schema::create('auto_quote_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->unique()->constrained('requests')->cascadeOnDelete();
            $t->timestamp('evaluated_at')->index();
            $t->boolean('eligible')->default(false)->index();
            // На какой проверке правило остановилось — null у пригодных.
            $t->string('stopped_at', 32)->nullable()->index();
            // Версия правила: правку правила видно по смене версии, и старые
            // снимки не выдают себя за решения нынешнего автомата.
            $t->string('rule_version', 16);
            $t->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->string('pricing', 160)->nullable();
            $t->decimal('total', 14, 2)->default(0);
            $t->jsonb('lines')->nullable();
            $t->jsonb('checks')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_quote_snapshots');
    }
};
