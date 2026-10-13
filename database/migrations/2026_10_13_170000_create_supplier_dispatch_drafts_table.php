<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Черновик запроса поставщикам во вкладке «Поставщики» заявки: что менеджер
 * выбрал и написал, пока не отправил. Без него переход на другую вкладку
 * заявки сбрасывал форму. Один черновик на менеджера и заявку.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_dispatch_drafts')) {
            return;
        }

        Schema::create('supplier_dispatch_drafts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->jsonb('payload');
            $t->timestamps();
            $t->unique(['request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_dispatch_drafts');
    }
};
