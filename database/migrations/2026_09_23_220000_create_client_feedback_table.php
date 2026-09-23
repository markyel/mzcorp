<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обратная связь клиентов о работе с нами.
 *
 * Сюда идёт то, что требует управленческого решения: «долго отвечаете на
 * почту», «сложно попасть на склад», «сорвали срок». Отзыв без решения —
 * просто жалоба, поэтому у записи есть не только слова клиента, но и вывод,
 * ответственный и состояние: взяли в работу, сделали, отклонили с причиной.
 *
 * Хорошее из отзывов сюда не складываем — ему место в медиапрофиле, откуда
 * оно работает на рекламный образ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_feedback')) {
            return;
        }

        Schema::create('client_feedback', function (Blueprint $table) {
            $table->id();
            // Откуда узнали: yandex_maps | email | call | meeting | survey | other
            $table->string('source', 24)->default('other')->index();
            $table->string('source_url', 500)->nullable();
            // Кто сказал: организация, имя или адрес — свободным текстом.
            $table->string('client', 255)->nullable();
            // Слова клиента как есть — без пересказа.
            $table->text('quote');
            // О чём это: почта, склад, сроки, цены, документы, сайт…
            $table->string('topic', 64)->nullable()->index();
            // new | in_progress | done | rejected
            $table->string('status', 16)->default('new')->index();
            // Что решили и что сделали.
            $table->text('decision')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_feedback');
    }
};
