<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка «интент входящего УСПЕШНО классифицирован LLM» (Foundation §7.2).
 * Ставится InboundIntentClassifier'ом только когда модель реально ответила
 * (включая unclear) — НЕ ставится при транзиентном сбое LLM (429/quota), где
 * классификатор fail-safe возвращает null. По этой колонке догоняющий крон
 * `mail:classify-intent-pending` находит письма, чья классификация пролетела в
 * окно сбоя AI, и повторяет её. Кейс M-2026-2302 (OpenAI 429 15.06).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_messages', 'intent_classified_at')) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->timestamp('intent_classified_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_messages', 'intent_classified_at')) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->dropColumn('intent_classified_at');
            });
        }
    }
};
