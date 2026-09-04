<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Рекламные блоки в исходящих письмах клиентам (картинка + заголовок +
 * текст + ссылка). Вставляются под подписью менеджера при отправке через
 * MyLift; из активных выбирается случайный. См. MarketingBlockService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_blocks')) {
            return;
        }

        Schema::create('marketing_blocks', function (Blueprint $t) {
            $t->id();
            $t->string('title', 120);
            $t->string('text', 300);
            $t->string('url', 500);
            // Путь на диске MarketingBlock::IMAGE_DISK (private) — отдаётся через
            // MarketingBlockImageController, в письмо встраивается как CID.
            $t->string('image_path', 255)->nullable();
            $t->boolean('is_active')->default(true)->index();
            // Сколько раз блок ушёл в реальных письмах (тестовые не считаем).
            $t->unsignedInteger('impressions_count')->default(0);
            $t->timestamp('last_used_at')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_blocks');
    }
};
