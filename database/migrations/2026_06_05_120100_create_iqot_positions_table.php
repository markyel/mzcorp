<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IQOT position — пул анализа цен + кэш результата ПО ПОЗИЦИИ КАТАЛОГА.
 * Одна строка на catalog_item (unique). Источники наполнения:
 *  - auto: позиции из проигранных КП (quotation.status=sent, request closed_lost),
 *    дедуп по catalog_item_id, lost_quote_count = частота → приоритет;
 *  - manual: ручное добавление из карточки каталога (РОП/директор) → приоритет.
 * Свежий (analyzed_at в окне iqot.report_fresh_days) отчёт = позицию не
 * пере-анализируем; используется для подсветки в редакторе КП и карточке каталога.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('iqot_positions')) {
            return;
        }

        Schema::create('iqot_positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            $t->foreignId('iqot_submission_id')->nullable()->constrained('iqot_submissions')->nullOnDelete();
            $t->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // pending → queued → analyzing → completed | failed
            $t->string('status', 24)->default('pending')->index();
            // auto (из проигранных КП) | manual (из каталога РОПом/директором)
            $t->string('source', 16)->default('auto');
            // Частота позиции в проигранных КП — сигнал приоритета.
            $t->unsignedInteger('lost_quote_count')->default(0);
            $t->timestamp('manual_requested_at')->nullable();

            // Снимок того, что отправили в IQOT (для трассировки/отображения).
            $t->string('client_ref', 128)->nullable();
            $t->string('payload_name', 500)->nullable();
            $t->string('payload_oem', 255)->nullable();
            $t->string('payload_brand', 255)->nullable();

            // Результат анализа (по позиции, выделенный из отчёта submission).
            $t->jsonb('report')->nullable();
            $t->decimal('report_min_price', 14, 2)->nullable();
            $t->unsignedInteger('report_offers_count')->nullable();
            $t->timestamp('analyzed_at')->nullable()->index();
            $t->timestamp('last_enqueued_at')->nullable()->index();

            $t->string('error_code', 64)->nullable();
            $t->text('error_message')->nullable();

            $t->timestamps();

            $t->unique('catalog_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iqot_positions');
    }
};
