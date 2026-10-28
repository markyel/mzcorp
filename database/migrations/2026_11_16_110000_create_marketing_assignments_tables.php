<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поручения маркетинга соседним отделам: завести ящик, сверстать страницу,
 * выложить пресс-кит. У исполнителей нет учётных записей в MyLift, поэтому
 * поручение — это подробная инструкция с файлами, которую выгружают архивом
 * и передают, а статус ведут здесь.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_assignments')) {
            Schema::create('marketing_assignments', function (Blueprint $table) {
                $table->id();
                $table->string('title', 300);
                // Кому: отдел или человек, и как с ним связаться.
                $table->string('executor', 200)->nullable();
                $table->string('executor_contact', 200)->nullable();
                // Коротко, что нужно, — из этого пишется подробная инструкция.
                $table->text('brief')->nullable();
                // Инструкция исполнителю (markdown): цель, шаги, требования, приёмка.
                $table->text('instructions')->nullable();
                // draft | handed | in_progress | done | cancelled
                $table->string('status', 16)->default('draft')->index();
                $table->unsignedTinyInteger('priority')->default(2);
                $table->date('due_on')->nullable()->index();
                $table->timestamp('handed_at')->nullable();
                $table->timestamp('done_at')->nullable();
                // Что сделано, ссылки на результат, замечания при приёмке.
                $table->text('result_notes')->nullable();
                $table->string('model', 64)->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_assignment_files')) {
            Schema::create('marketing_assignment_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('marketing_assignment_id')->constrained('marketing_assignments')->cascadeOnDelete();
                $table->string('filename', 255);
                $table->string('path', 500);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                // Что это за файл для исполнителя: «шаблон страницы», «логотип SVG».
                $table->string('note', 300)->nullable();
                $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_assignment_files');
        Schema::dropIfExists('marketing_assignments');
    }
};
