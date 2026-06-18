<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр поставщиков (модуль поставщиков). Список email и/или доменов, которые
 * мы считаем поставщиками. Используется как первый гейт: наше ИСХОДЯЩЕЕ письмо
 * получателю из этого реестра + LLM-подтверждение «это запрос расценки (RFQ)»
 * → регистрируем тред как запрос поставщику (SupplierInquiry), чтобы ответы
 * поставщика не плодили фейковые клиентские заявки.
 *
 * Контрагент бывает И клиентом, И поставщиком — поэтому одного реестра мало,
 * добивает LLM-проверка содержания исходящего. См. SupplierRegistry,
 * SupplierRfqClassifier, SupplierInquiryService::createFromOutbound.
 *
 * Запись = email ИЛИ домен (хотя бы одно непустое; гарант — UI/сервис).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppliers')) {
            return;
        }
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable()->index();
            $table->string('domain')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
