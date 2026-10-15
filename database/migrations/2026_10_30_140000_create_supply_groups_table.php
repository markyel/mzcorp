<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Справочник групп товаров (Китай / Россия / Европа и новые): код уходит в QuoteParts,
        // по группам поставщики видят позиции. Группа — она же регион поставщика 1С: позиция
        // попадает в группу, если её закупали у поставщика этого региона; exclude_if_code —
        // «но не если она есть и в группе …» (Европа — то, что не везём из Китая).
        if (! Schema::hasTable('supply_groups')) {
            Schema::create('supply_groups', function (Blueprint $table) {
                $table->id();
                $table->string('code', 16)->unique();
                $table->string('name', 64);
                $table->unsignedSmallInteger('position')->default(0);
                $table->string('exclude_if_code', 16)->nullable();
                $table->timestamps();
            });
            DB::table('supply_groups')->insert([
                ['code' => 'cn', 'name' => 'Китай', 'position' => 1, 'exclude_if_code' => null, 'created_at' => now(), 'updated_at' => now()],
                ['code' => 'ru', 'name' => 'Россия', 'position' => 2, 'exclude_if_code' => null, 'created_at' => now(), 'updated_at' => now()],
                ['code' => 'eu', 'name' => 'Европа', 'position' => 3, 'exclude_if_code' => 'cn', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_groups');
    }
};
