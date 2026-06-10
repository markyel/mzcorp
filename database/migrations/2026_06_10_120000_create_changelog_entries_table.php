<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раздел «Обновления» (changelog): лента важных для участников изменений.
 * Тело — markdown. Публикация — privileged роли (head_of_sales/director/admin).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('changelog_entries')) {
            return;
        }

        Schema::create('changelog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('body')->comment('markdown');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changelog_entries');
    }
};
