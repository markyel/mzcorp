<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник «что нужно, чтобы опознать деталь» — в базе и пополняется сам (заказчик 28.11.2026).
 *
 *  - identification_part_types — типы деталей: как узнать по названию позиции (pattern, главное слово);
 *  - identification_hints — вопросы клиенту по типу: сколько раз их задавали менеджеры, статус
 *    (active — подсказка; candidate — найден в письмах, станет подсказкой после 3 разных писем; disabled);
 *  - identification_observations — разобранные письма-уточнения менеджеров: какой вопрос, к какой детали
 *    (сырьё для счётчиков и для новых типов; письмо без вопросов — строка без вопроса, чтобы не разбирать снова).
 *
 * Начальное наполнение — config/identification_hints.php (свод 793 писем, 28.11.2026): identification:seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identification_part_types')) {
            Schema::create('identification_part_types', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->string('name', 120);
                $table->text('pattern')->nullable();
                $table->unsignedSmallInteger('sort')->default(100);
                // Тип, найденный в письмах, ждёт подтверждения: правило распознавания составлено автоматически.
                $table->boolean('is_active')->default(true);
                $table->string('source', 16)->default('seed'); // seed | auto | manual
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('identification_hints')) {
            Schema::create('identification_hints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('part_type_id')->index()->constrained('identification_part_types')->cascadeOnDelete();
                $table->string('label', 120);
                $table->text('question');
                $table->string('status', 16)->default('active'); // active | candidate | disabled
                $table->string('source', 16)->default('seed');
                $table->unsignedInteger('asked_count')->default(0);
                $table->timestamp('last_asked_at')->nullable();
                $table->unsignedSmallInteger('sort')->default(100);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('identification_observations')) {
            Schema::create('identification_observations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('email_message_id')->index();
                $table->unsignedBigInteger('request_id')->nullable()->index();
                $table->foreignId('part_type_id')->nullable()->index()->constrained('identification_part_types')->nullOnDelete();
                $table->foreignId('hint_id')->nullable()->index()->constrained('identification_hints')->nullOnDelete();
                $table->string('part_type_raw', 120)->nullable();
                $table->string('attribute_raw', 160)->nullable();
                $table->text('question')->nullable();
                $table->string('purpose', 16)->nullable(); // identify | commercial | other | none
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('identification_observations');
        Schema::dropIfExists('identification_hints');
        Schema::dropIfExists('identification_part_types');
    }
};
