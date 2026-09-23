<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Профили конкурентов по их отзывам.
 *
 * Отзыв о конкуренте — это не сплетня, а описание того, чего рынок ждёт от
 * поставщика запчастей. Из чужих отзывов вынимаем две вещи: чем мы лучше
 * (это идёт в медиапрофиль и работает на рекламу) и чем мы хуже (это идёт в
 * обратную связь и требует управленческого решения).
 *
 * Отзывы храним дословно и с ссылкой на источник: без цитаты вывод нечем
 * подтвердить, а площадки со временем правят и удаляют отзывы.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competitors')) {
            Schema::create('competitors', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->string('site', 255)->nullable();
                // Основная площадка отзывов: Яндекс.Карты, 2ГИС, Zoon…
                $table->string('platform', 64)->nullable();
                $table->string('platform_url', 500)->nullable();
                // Витрина площадки — как есть на день сбора.
                $table->decimal('rating', 3, 2)->nullable();
                $table->unsignedInteger('ratings_count')->nullable();
                $table->unsignedInteger('reviews_count')->nullable();
                // Чем занимается, чем силён — свободным текстом.
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competitor_reviews')) {
            Schema::create('competitor_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('competitor_id')->constrained('competitors')->cascadeOnDelete();
                $table->string('source', 64)->nullable();
                $table->string('source_url', 500)->nullable();
                $table->string('author', 160)->nullable();
                // Слова клиента конкурента как есть.
                $table->text('quote');
                $table->decimal('rating', 3, 2)->nullable();
                $table->date('posted_on')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competitor_insights')) {
            Schema::create('competitor_insights', function (Blueprint $table) {
                $table->id();
                $table->foreignId('competitor_id')->nullable()->constrained('competitors')->cascadeOnDelete();
                // advantage — чем мы лучше; weakness — чем мы хуже.
                $table->string('kind', 16)->index();
                // Вывод одной фразой.
                $table->string('statement', 500);
                // Чем подтверждается: цитата из отзыва конкурента.
                $table->text('evidence')->nullable();
                // Куда просится: грань медиапрофиля или тема обратной связи.
                $table->string('facet', 32)->nullable();
                $table->string('topic', 64)->nullable();
                // new | accepted | dismissed — кандидат, пока человек не решил.
                $table->string('status', 16)->default('new')->index();
                // Куда в итоге легло, чтобы не завести дубль второй раз.
                $table->foreignId('media_profile_entry_id')->nullable()->constrained('media_profile_entries')->nullOnDelete();
                $table->foreignId('client_feedback_id')->nullable()->constrained('client_feedback')->nullOnDelete();
                $table->string('model', 64)->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_insights');
        Schema::dropIfExists('competitor_reviews');
        Schema::dropIfExists('competitors');
    }
};
