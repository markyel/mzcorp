<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // День, когда срок темы сдвинул выпуск: второй канал того же выпуска позже в тот же
        // день срок уже не двигает (09.10.2026 дайджест ушёл на две недели вперёд).
        if (Schema::hasTable('media_topics') && ! Schema::hasColumn('media_topics', 'advanced_on')) {
            Schema::table('media_topics', fn (Blueprint $table) => $table->date('advanced_on')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('media_topics', 'advanced_on')) {
            Schema::table('media_topics', fn (Blueprint $table) => $table->dropColumn('advanced_on'));
        }
    }
};
