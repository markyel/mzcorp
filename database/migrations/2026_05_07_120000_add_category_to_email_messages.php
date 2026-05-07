<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.8c: новая категоризация (drop-in из LazyLift Flow 1).
 *
 * Старые ai_classification / ai_classification_confidence / classified_at
 * остаются для обратной совместимости (используются в MailRoutingRule).
 *
 * Новые поля независимы; категория определяет, попадёт ли письмо в парсер
 * заявки и создаст ли Request.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('category', 32)->nullable()->index()
                ->comment('App\\Enums\\EmailCategory: client_request | thread_reply | irrelevant');
            $table->decimal('category_confidence', 4, 3)->nullable()
                ->comment('Уверенность классификатора 0..1');
            $table->string('category_intent', 32)->nullable()
                ->comment('confirm_order для thread_reply, иначе null');
            $table->text('category_reasoning')->nullable()
                ->comment('Краткое обоснование AI на русском');
            $table->timestamp('categorized_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            foreach (
                ['category', 'category_confidence', 'category_intent', 'category_reasoning', 'categorized_at']
                as $col
            ) {
                if (Schema::hasColumn('email_messages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
