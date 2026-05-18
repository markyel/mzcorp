<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot исходящего КП/счёта (Foundation §7, расширение DocumentDetector'а).
 *
 * Когда менеджер шлёт клиенту PDF/XLSX/DOCX «Предложение МЗ-NNNNNN.pdf» через
 * Yandex web UI или MyLift compose, и `OutboundDocumentDetector` /
 * `OutboundDocumentClassifier` определил тип `quotation` / `invoice`,
 * `ParseOutboundQuoteJob` извлекает позиции из файла через
 * `OutboundQuoteParsingService` (drop-in движка `LazyLift @ 7fee1f77`
 * `QuoteParsingService`) и сохраняет snapshot ОТПРАВЛЕННОГО документа сюда.
 *
 * Назначение:
 *  - Аудит «что именно мы выслали клиенту» (RequestItem'ы могут потом
 *    изменяться — здесь зафиксирован slice на момент отправки).
 *  - Hero-чип «Сумма КП: 156 230 ₽ · 12/15 позиций».
 *  - Auto-enrich `RequestItem.catalog_item_id` если менеджер вручную подобрал
 *    M-SKU в КП, а позиция заявки была unresolved (см. OutboundQuoteItemMatcher).
 *
 * Идемпотентность — UNIQUE(email_attachment_id). Один файл = одна запись.
 * Если детектор сработал на письме без файла (body-only quotation_keyword) —
 * email_attachment_id = null, идентификатор уникальности — (email_message_id,
 * source='body').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outbound_quotes')) {
            return;
        }

        Schema::create('outbound_quotes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('request_id')
                ->constrained('requests')->cascadeOnDelete();
            $table->foreignId('email_message_id')
                ->constrained('email_messages')->cascadeOnDelete();
            // Источник содержимого: вложение (pdf/xlsx/docx) или body письма.
            $table->foreignId('email_attachment_id')->nullable()
                ->constrained('email_attachments')->cascadeOnDelete();

            // 'attachment' | 'body' — для будущих body-only кейсов
            // (когда КП оформлен прямо в теле письма таблицей).
            $table->string('source', 16)->default('attachment');

            // Тип события — соответствует App\Enums\DetectorType:
            //   outbound_quotation_full / outbound_invoice / outbound_quotation_partial.
            // Phase-1 фактически {quote, invoice} — partial оставлен как extension.
            $table->string('document_type', 32)->index();

            // Метаданные документа (извлекаются LLM из содержимого).
            $table->string('document_number', 128)->nullable();
            $table->date('document_date')->nullable();

            // Финансы — три суммы раздельно (Foundation §7.1).
            $table->string('currency', 8)->default('RUB');
            $table->decimal('subtotal', 14, 2)->nullable();
            $table->decimal('vat_amount', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->boolean('prices_include_vat')->nullable();

            // Pipeline status: parsing → parsed → matched | failed.
            $table->string('status', 16)->default('parsing')->index();
            $table->text('parse_error')->nullable();

            // raw response от LLM (для дебага парсера и retraining).
            $table->jsonb('ai_raw_response')->nullable();
            // Дополнительные метаданные (signals, model, tokens, processing_ms).
            $table->jsonb('payload')->nullable();

            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('matched_at')->nullable();

            $table->timestamps();

            // Идемпотентность по вложению (один файл = один snapshot).
            $table->unique('email_attachment_id', 'outbound_quotes_attachment_uq');
            $table->index(['request_id', 'document_type'], 'outbound_quotes_request_type_idx');
            $table->index('email_message_id', 'outbound_quotes_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_quotes');
    }
};
