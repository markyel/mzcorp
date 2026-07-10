<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шаблоны писем для вкладки «Переписка» (composer).
 *
 * Менеджеры пишут типовые письма клиентам. Эта таблица хранит многоразовую
 * библиотеку шаблонов в ДРЕВОВИДНОЙ структуре (adjacency-list, как
 * Request.inheritance_parent_id): ходовые — в корне (parent_id IS NULL),
 * узкотематические — в подпапках.
 *
 * Один узел:
 *   is_folder=true  — папка (тело игнорируется, только группирует детей);
 *   is_folder=false — шаблон (body = PLAIN-TEXT тело письма).
 *
 * Тело хранится plain-text: composer ($bodyText) тоже plain-text, подпись и
 * цитата приклеиваются только при отправке (OutgoingMailMimeBuilder). Хранить
 * подпись в шаблоне нельзя — будет дубль.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('letter_templates', function (Blueprint $table) {
            $table->id();

            // Ветка дерева. NULL = корневой узел. Каскад: удаление папки
            // уносит поддерево (в UI подтверждаем через wire:confirm).
            $table->foreignId('parent_id')->nullable()
                ->constrained('letter_templates')->cascadeOnDelete()
                ->comment('Родительский узел (папка). NULL = корень');

            $table->boolean('is_folder')->default(false)->index()
                ->comment('true = папка (body игнорируется), false = шаблон');

            $table->string('name', 160)->comment('Название узла для UI');

            $table->string('subject', 998)->nullable()
                ->comment('Опциональная тема письма (подставляется если тема пуста)');

            $table->text('body')->nullable()
                ->comment('PLAIN-TEXT тело письма. null для папок');

            $table->integer('sort_order')->default(0)->index()
                ->comment('Порядок среди соседей');

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_templates');
    }
};
