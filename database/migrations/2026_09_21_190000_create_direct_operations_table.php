<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал операций с API Директа.
 *
 * Всё, что мы делаем снаружи, — это деньги и модерация: создали группу,
 * отправили объявление, поменяли ставку. Через неделю «почему у этой позиции
 * два объявления» по логам приложения не восстановить, поэтому каждый вызов
 * пишется сюда: что отправили, что ответили, сколько стоило баллов и кто нажал.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('direct_operations')) {
            return;
        }

        Schema::create('direct_operations', function (Blueprint $t) {
            $t->id();
            $t->string('service', 32);
            $t->string('method', 32);
            $t->string('sku', 32)->nullable()->index();
            $t->boolean('ok')->default(false);
            $t->jsonb('request')->nullable();
            $t->jsonb('response')->nullable();
            $t->integer('units_spent')->nullable();
            $t->integer('units_rest')->nullable();
            $t->integer('error_code')->nullable();
            $t->string('error_message', 500)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_operations');
    }
};
