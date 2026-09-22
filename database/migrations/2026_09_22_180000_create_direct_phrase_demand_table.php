<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Спрос на поисковую фразу по данным Директа.
 *
 * Рекламироваться по строке, которой нет в поиске, бессмысленно: замер
 * 22.09.2026 показал ноль показов в месяц у 274 наших фраз из 382 — по таким
 * запросам Яндекс вообще не проводит аукцион, и рекламного блока в выдаче нет
 * ни у кого. Частоту отдаёт только старый Live v4, по 100 фраз за запрос и с
 * задержкой, поэтому ответы храним и переспрашиваем раз в несколько недель.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('direct_phrase_demand')) {
            return;
        }

        Schema::create('direct_phrase_demand', function (Blueprint $table) {
            $table->id();
            $table->string('phrase')->unique();
            $table->unsignedInteger('shows')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_phrase_demand');
    }
};
