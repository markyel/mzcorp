<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Подписки пула QuoteParts: «группа + фильтры» — подходящие позиции держатся в пуле сами
        // (ежечасно и после загрузки «Закупок 1С»), переставшие подходить — к удалению.
        if (! Schema::hasTable('quoteparts_pool_rules')) {
            Schema::create('quoteparts_pool_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('group_code', 16)->nullable();
                $table->jsonb('filters')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('matched_count')->default(0);
                $table->timestamp('applied_at')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Откуда позиция в пуле: выбрана руками или пришла по правилу.
        if (! Schema::hasColumn('quoteparts_pool_items', 'source')) {
            Schema::table('quoteparts_pool_items', fn (Blueprint $table) => $table->string('source', 16)->default('manual'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quoteparts_pool_items', 'source')) {
            Schema::table('quoteparts_pool_items', fn (Blueprint $table) => $table->dropColumn('source'));
        }
        Schema::dropIfExists('quoteparts_pool_rules');
    }
};
