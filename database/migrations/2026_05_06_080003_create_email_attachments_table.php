<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вложения писем.
 *
 * Foundation §«Не моделируем» в EmailAttachment не упомянут — он есть в списке
 * «Новые» (см. Foundation §«Новые» в моделях). На Phase 4 DocumentDetector
 * читает PDF/XLSX вложения для распознавания КП/счетов.
 *
 * Файлы кладём в storage (filesystem disk = local на старте), путь — в file_path.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();

            $table->string('filename');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('content_id')->nullable()->comment('Для inline-вложений: cid: ссылки в HTML');
            $table->string('file_path')->comment('Относительный путь в storage');
            $table->string('disk', 32)->default('local');
            $table->boolean('is_inline')->default(false);

            $table->timestamps();

            $table->index('mime_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
