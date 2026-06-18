<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Запрос расценки/наличия поставщику (фундамент модуля поставщиков).
 *
 * Менеджер шлёт поставщику запрос («Запрос наличия/стоимости/сроков поставки»,
 * «[NNNNNN] запрос от Мой Лифт»), часто из Яндекса напрямую — мимо MyLift,
 * поэтому исходящего у нас в БД нет. Поставщик отвечает, и его ответ
 * (thread_reply, In-Reply-To на наш @myzip.ru) линкер не находит к чему
 * прицепить → IncomingMailProcessor плодит ФАНТОМНУЮ клиентскую заявку
 * (кейс 0028087@mail.ru: 8 фейковых заявок, 42/42 письма — ответы на наши треды).
 *
 * Оператор помечает пойманный тред как «наш запрос поставщику» → создаётся
 * SupplierInquiry с thread_root_id (корень цепочки треда). Дальнейшие ответы
 * в ЭТОМ треде матчатся по цепочке (In-Reply-To/References ↔ thread_root_id /
 * message_id уже прикреплённых писем) и ложатся как переписка с поставщиком,
 * НЕ создавая заявок. Тред-центрично: срабатывает только на явно помеченных
 * тредах (без авто-подавления по контакту).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_inquiries')) {
            return;
        }
        Schema::create('supplier_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_email')->index();
            $table->string('supplier_name')->nullable();
            $table->string('subject', 998)->nullable();
            // Корень цепочки треда (Message-ID нашего исходящего запроса,
            // вытащенный из In-Reply-To/References пойманного ответа) — ключ
            // матчинга последующих ответов в этом же треде.
            $table->string('thread_root_id', 998)->nullable()->index();
            // Клиентская заявка, под которую сорсим (если оператор связал).
            // Чаще null — исходящий запрос ушёл мимо MyLift.
            $table->foreignId('related_request_id')->nullable()
                ->constrained('requests')->nullOnDelete();
            $table->string('status', 16)->default('open'); // open | closed
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_inquiries');
    }
};
