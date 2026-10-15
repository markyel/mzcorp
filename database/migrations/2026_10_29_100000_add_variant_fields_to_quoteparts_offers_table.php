<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['brand', 'part_number', 'product_name', 'note', 'photos'];

    public function up(): void
    {
        // Аналог, копия, восстановленное, замена: что за товар предложен, состояние и сколько фото.
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'brand')) {
                $table->string('brand', 100)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'part_number')) {
                $table->string('part_number', 100)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'product_name')) {
                $table->string('product_name')->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'note')) {
                $table->text('note')->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'photos')) {
                $table->unsignedSmallInteger('photos')->default(0);
            }
        });
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('quoteparts_offers', $column)) {
                Schema::table('quoteparts_offers', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
