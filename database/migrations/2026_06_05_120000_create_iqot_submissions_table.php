<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IQOT submission — батч позиций, отправленный в IQOT Public API на анализ цен.
 * Async-poll-only: POST /submissions создаёт запись, статус/отчёт забираются
 * GET-ами с учётом X-Next-Check-After. Кросс-заявочно (позиции пула каталога),
 * поэтому без request_id — связь с конкретными позициями через iqot_positions.
 * Порт из LazyLift (app/Models/IqotSubmission.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('iqot_submissions')) {
            return;
        }

        Schema::create('iqot_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $t->string('idempotency_key', 128)->unique();
            $t->string('submission_id', 64)->nullable()->index();
            $t->string('client_ref', 128)->nullable();

            // draft → sending → accepted → processing → collecting → ready_minimum → completed | cancelled | failed
            $t->string('local_status', 32)->default('draft');
            $t->string('iqot_status', 32)->nullable();
            $t->string('iqot_stage', 64)->nullable();

            // catalog_items.id[] позиций, вошедших в submission.
            $t->jsonb('catalog_item_ids');
            $t->jsonb('payload')->nullable();
            $t->jsonb('last_status_response')->nullable();
            $t->jsonb('report')->nullable();

            $t->timestamp('status_changed_at')->nullable();
            $t->timestamp('next_check_after')->nullable()->index();
            $t->timestamp('last_polled_at')->nullable();
            $t->timestamp('report_fetched_at')->nullable();

            $t->string('error_code', 64)->nullable();
            $t->text('error_message')->nullable();
            $t->string('request_id_header', 64)->nullable();

            $t->timestamps();

            $t->index('local_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iqot_submissions');
    }
};
