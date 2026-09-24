<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Медиаплан: каналы, темы и публикации.
 *
 * Медиапрофиль отвечает на вопрос «как мы говорим». Этот слой отвечает на два
 * других: где мы говорим (каналы) и о чём, с какой регулярностью (темы) —
 * и хранит сами материалы вместе с их судьбой: черновик, на проверке,
 * согласован, опубликован, со ссылкой на публикацию.
 *
 * Тема и канал разведены сознательно: одна и та же тема идёт и в рассылку, и
 * в Телеграм, и текст там разный, а план регулярности — на теме.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_channels')) {
            Schema::create('media_channels', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                // direct | mailing | email_block | telegram | zen | vk | site | other
                $table->string('kind', 24)->index();
                $table->string('url', 500)->nullable();
                // Как к каналу обращаться технически: @канал, id сообщества, код блока.
                $table->string('handle', 160)->nullable();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                // Сколько публикаций в неделю ждём от канала — план, а не обещание.
                $table->unsignedSmallInteger('posts_per_week')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('media_topics')) {
            Schema::create('media_topics', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200);
                // Бриф: о чём тема, для кого, что в ней должно быть всегда.
                $table->text('brief')->nullable();
                /*
                 * Откуда берётся материал:
                 *   manual         — пишет человек / модель по брифу;
                 *   catalog_new    — новые позиции каталога;
                 *   catalog_price  — позиции со сниженной ценой;
                 *   stock_arrivals — поступления на склад;
                 *   request_tips   — советы по оформлению заявок из наших же уточнений;
                 *   news           — события компании (выставки и прочее).
                 */
                $table->string('source', 24)->default('manual')->index();
                // Регулярность в днях: 7 — раз в неделю. NULL — по случаю.
                $table->unsignedSmallInteger('cadence_days')->nullable();
                // Когда тема ждёт следующей публикации (план, двигается вручную и автоматом).
                $table->date('next_due_on')->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('media_publications')) {
            Schema::create('media_publications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('media_topic_id')->nullable()->constrained('media_topics')->nullOnDelete();
                $table->foreignId('media_channel_id')->nullable()->constrained('media_channels')->nullOnDelete();
                $table->string('title', 300)->nullable();
                $table->text('body')->nullable();
                // idea | draft | in_review | approved | published | rejected
                $table->string('status', 16)->default('draft')->index();
                $table->date('planned_for')->nullable()->index();
                $table->timestamp('published_at')->nullable();
                $table->string('url', 500)->nullable();
                // Чем сгенерировано (если сгенерировано) и каким разбором проверено.
                $table->string('model', 64)->nullable();
                $table->foreignId('media_profile_review_id')->nullable()
                    ->constrained('media_profile_reviews')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_publications');
        Schema::dropIfExists('media_topics');
        Schema::dropIfExists('media_channels');
    }
};
