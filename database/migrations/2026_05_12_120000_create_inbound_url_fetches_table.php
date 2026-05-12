<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кэш результатов веб-фетча URL'ов, извлечённых из входящих писем.
 *
 * Используется RequestItemParsingService: перед LLM-разбором тела письма
 * берём все http(s)-ссылки, синхронно фетчим, складываем выжимку текста
 * сюда, отдаём в промпт как секцию `## ИЗВЛЕЧЁННЫЙ ТЕКСТ ИЗ ССЫЛОК`.
 *
 * Идемпотентность — по `url_hash` (sha256 от нормализованного URL).
 * Повторное письмо с тем же URL подхватит кэшированную выжимку и
 * не пойдёт на сайт.
 *
 * Статусы (status):
 *   - success:              успешно получили читаемый текст.
 *   - http_error:           4xx/5xx от сервера.
 *   - ssrf_blocked:         resolve вернул приватный/loopback/link-local IP.
 *   - timeout:              превысили per-URL timeout.
 *   - size_exceeded:        Content-Length > лимита или превышен в стриме.
 *   - wrong_content_type:   Content-Type не в whitelist.
 *   - parse_error:          DOM-парсинг упал.
 *   - skipped_budget:       total budget на письмо исчерпан до этого URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_url_fetches')) {
            Schema::create('inbound_url_fetches', function (Blueprint $table) {
                $table->id();
                $table->string('url_hash', 64)->unique()->comment('sha256 нормализованного URL');
                $table->text('url');
                $table->string('host', 255)->nullable()->index();

                $table->string('status', 32)->index();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('content_type', 128)->nullable();
                $table->unsignedInteger('content_length')->nullable();

                $table->text('extracted_text')->nullable()
                    ->comment('очищенный читаемый текст (title + og + visible text, обрезанный до cfg limit)');
                $table->text('error_message')->nullable();

                $table->timestamp('fetched_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_url_fetches');
    }
};
