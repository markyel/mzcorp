<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * КП (исходящее коммерческое предложение от ООО "Мой Лифт" клиенту).
 *
 * Архитектура (см. CLAUDE.md / MEMORY.md):
 *  - Одна Quotation на Request, версии стекаются (version инкрементируется
 *    при freezeVersion() / при отправке клиенту). Активна последняя.
 *  - Hybrid версионирование: drafts in-place editing + кнопка
 *    «Закрепить версию» + автомат при send.
 *  - Внутренний код КП-2026-NNNN (свой PostgreSQL sequence, не пересекается
 *    с MDB-номерами счетов мз-NNNN из 1С).
 *  - Snapshot всех данных компании-исполнителя в `snapshot_company` jsonb
 *    при отправке — если поменяем реквизиты в config, исторические КП
 *    останутся неизменными.
 *  - Recipient (заказчик) хранится здесь, а не на Request: client_email
 *    в Request — лишь источник, реквизиты заказчика (ИНН/адрес) менеджер
 *    вбивает в КП-редакторе. На MVP — nullable, оператор заполняет.
 */
return new class extends Migration {
    public function up(): void
    {
        // Sequence для internal_code КП-2026-NNNN. Стартует с 1 каждый
        // календарный год через QuotationService::generateInternalCode()
        // (логика в PHP — sequence просто монотонно растёт, year-префикс
        // делает код уникальным per year).
        DB::statement('CREATE SEQUENCE IF NOT EXISTS quotations_seq START 1');

        if (! Schema::hasTable('quotations')) {
            Schema::create('quotations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();

                // КП-2026-NNNN (формат как M-2026-NNNN у заявок).
                $table->string('internal_code', 32)->unique();
                $table->unsignedSmallInteger('version')->default(1);
                // QuotationStatus enum value: draft / sent / accepted / rejected / cancelled.
                $table->string('status', 20)->default('draft')->index();

                // Recipient (заказчик в шапке PDF). Default из Request->client_name,
                // ИНН/адрес — менеджер вбивает руками (нет в Request).
                $table->string('recipient_name', 255)->nullable();
                $table->string('recipient_inn', 20)->nullable();
                $table->text('recipient_address')->nullable();
                $table->text('recipient_card_text')->nullable(); // «карта клиента» в шапке PDF (на MVP пусто)

                // Ответственный (FIO + контакты в шапке PDF). Default = Request->assigned_user.
                $table->foreignId('responsible_user_id')->nullable()
                    ->constrained('users')->nullOnDelete();

                // Срок действия КП в календарных днях (PDF: «Гарантировано до DD.MM.YYYY» = created_at + valid_days).
                $table->unsignedSmallInteger('valid_days')->default(5);

                // Общая скидка %. Применяется ко всем items если у item discount_percent NULL.
                // PDF: «Скидка 11.54%».
                $table->decimal('discount_percent', 5, 2)->default(0);

                // Итоги (пересчитываются QuotationService::recalcTotals() при каждом изменении items).
                $table->decimal('subtotal', 14, 2)->default(0);        // сумма line_total без скидки
                $table->decimal('discount_amount', 14, 2)->default(0); // сэкономлено клиенту
                $table->decimal('total', 14, 2)->default(0);           // итого со скидкой (включая НДС)
                $table->decimal('vat_rate', 5, 2)->default(22);        // НДС % (берётся из app_setting tax.vat_percent)
                $table->decimal('vat_amount', 14, 2)->default(0);      // НДС в т.ч. (для PDF)

                // Email-отправка (Phase 4 — связка с ComposeForm).
                $table->foreignId('sent_email_message_id')->nullable()
                    ->constrained('email_messages')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();

                // Snapshot реквизитов компании-исполнителя (ООО Мой Лифт) на момент
                // sent — для immutability. config('services.company') может поменяться
                // в будущем (адрес, ЭДО ID), но исторические КП должны рендериться
                // с теми же данными что отправляли клиенту.
                $table->jsonb('snapshot_company')->nullable();

                $table->text('notes')->nullable();
                $table->foreignId('created_by_user_id')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['request_id', 'version']);
                $table->index('sent_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
        DB::statement('DROP SEQUENCE IF EXISTS quotations_seq');
    }
};
