<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Пачка фото раздела «Фото»: загрузили → очистка фона (Clipping Magic) → архив с теми же именами.
        if (! Schema::hasTable('photo_batches')) {
            Schema::create('photo_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
                // draft — собирается; queued/processing — в работе; done — готово (архив); failed — не вышло ни одного.
                $table->string('status', 16)->default('draft')->index();
                $table->boolean('test')->default(false);
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('processed')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->string('archive_path', 500)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_batches');
    }
};
