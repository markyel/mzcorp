<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вид записи стоп-листа: spam (отбрасываем) | supplier (читаем как переписку
 * с поставщиком, не создаём заявок). См. App\Enums\BlocklistKind.
 *
 * Все существующие записи помечаем supplier: диагностика показала, что у 13 из
 * 14 есть наши исходящие RFQ (out_rfq≥2) — это пул поставщиков, заведённый,
 * чтобы их ответы не плодили фейк-заявки. Их переписку теперь нужно читать.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sender_blocklist', 'kind')) {
            Schema::table('sender_blocklist', function (Blueprint $table) {
                $table->string('kind', 16)->default('spam')->index();
            });
        }
        // Существующие записи — поставщики (см. docblock).
        DB::table('sender_blocklist')->update(['kind' => 'supplier']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sender_blocklist', 'kind')) {
            Schema::table('sender_blocklist', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
};
