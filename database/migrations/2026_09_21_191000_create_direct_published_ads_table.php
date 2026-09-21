<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Что из нашей очереди чем стало в Директе: позиция → группа, объявление,
 * фразы.
 *
 * Без этой таблицы вторая публикация создаст дубли, а отключение по остатку
 * будет нечем адресовать: в Директе нет нашего артикула, там только числовые
 * идентификаторы. Тексты храним снимком — по ним видно, расходится ли
 * опубликованное с тем, что сейчас в плане (расхождение = повторная модерация,
 * если отправлять).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('direct_published_ads')) {
            return;
        }

        Schema::create('direct_published_ads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->cascadeOnDelete();
            $t->string('sku', 32)->index();
            $t->bigInteger('campaign_id')->nullable()->index();
            $t->bigInteger('ad_group_id')->nullable()->index();
            $t->bigInteger('ad_id')->nullable()->index();
            $t->jsonb('keyword_ids')->nullable();
            // Снимок того, что реально ушло в Директ.
            $t->string('title', 120)->nullable();
            $t->string('title2', 60)->nullable();
            $t->string('text', 200)->nullable();
            $t->jsonb('keywords')->nullable();
            // Состояние по данным Директа: DRAFT, MODERATION, ON, OFF…
            $t->string('state', 24)->nullable();
            $t->string('status', 24)->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_published_ads');
    }
};
