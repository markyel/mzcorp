<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Группы поставщиков («Китай», «Европа», «Поручни», «Канаты»…). Поставщик
 * может быть в нескольких группах. При запросе цены группу выбирают целиком
 * вместо перебора поставщиков по одному.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_groups')) {
            Schema::create('supplier_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_group_members')) {
            Schema::create('supplier_group_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_group_id')->constrained('supplier_groups')->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['supplier_group_id', 'supplier_id']);
                $table->index('supplier_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_group_members');
        Schema::dropIfExists('supplier_groups');
    }
};
