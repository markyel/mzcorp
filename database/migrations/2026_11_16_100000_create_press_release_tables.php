<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пресс-релизы в медиаплане: база СМИ, релизы (инфоповод → текст), рассылка
 * по редакциям и публикации, которые из неё вышли.
 *
 * Рассылка — по письму в каждую редакцию (press_release_sends): у каждого
 * письма свой Message-ID, по нему ответ редакции находит свой релиз, а
 * площадка — свою статистику «отправили N, опубликовали M».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('press_outlets')) {
            Schema::create('press_outlets', function (Blueprint $table) {
                $table->id();
                $table->string('name', 200);
                // Лифтовая отрасль | ЖКХ и управление недвижимостью | Строительство и девелопмент | …
                $table->string('category', 64)->index();
                // A — каждый релиз, B — по теме, C — крупные поводы / платно.
                $table->char('priority', 1)->default('B')->index();
                $table->string('kind', 160)->nullable();
                $table->string('url', 500)->unique();
                // Адреса редакции: список строк, первый — основной.
                $table->jsonb('emails')->nullable();
                $table->text('other_contact')->nullable();
                $table->string('terms', 500)->nullable();
                $table->text('audience')->nullable();
                $table->text('notes')->nullable();
                // Где взят адрес — чтобы перепроверить, если письмо вернулось.
                $table->text('email_source')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('press_releases')) {
            Schema::create('press_releases', function (Blueprint $table) {
                $table->id();
                $table->string('title', 300);
                // Инфоповод: факты, из которых пишется релиз.
                $table->text('occasion')->nullable();
                $table->text('body')->nullable();
                $table->string('subject', 300)->nullable();
                // Сопроводительный текст письма над релизом.
                $table->text('cover_note')->nullable();
                // idea | draft | ready | sent | archived
                $table->string('status', 16)->default('idea')->index();
                $table->date('planned_for')->nullable()->index();
                $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->string('model', 64)->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('press_release_sends')) {
            Schema::create('press_release_sends', function (Blueprint $table) {
                $table->id();
                $table->foreignId('press_release_id')->constrained('press_releases')->cascadeOnDelete();
                $table->foreignId('press_outlet_id')->constrained('press_outlets')->cascadeOnDelete();
                $table->string('email', 255);
                // queued | sent | failed | replied
                $table->string('status', 16)->default('queued')->index();
                $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
                // Финальный Message-ID письма: по нему ловим ответ редакции.
                $table->string('message_id', 998)->nullable()->index();
                $table->text('error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->foreignId('reply_email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
                $table->timestamps();

                $table->unique(['press_release_id', 'press_outlet_id']);
            });
        }

        if (! Schema::hasTable('press_mentions')) {
            Schema::create('press_mentions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('press_release_id')->nullable()->constrained('press_releases')->nullOnDelete();
                $table->foreignId('press_outlet_id')->nullable()->constrained('press_outlets')->nullOnDelete();
                $table->string('url', 500);
                $table->string('title', 300)->nullable();
                $table->date('published_on')->nullable()->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('press_mentions');
        Schema::dropIfExists('press_release_sends');
        Schema::dropIfExists('press_releases');
        Schema::dropIfExists('press_outlets');
    }
};
