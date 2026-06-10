<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Краткое содержание записи для превью на дашборде. Если пусто — превью
 * выводится из начала тела (ChangelogEntry::previewText).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('changelog_entries', 'excerpt')) {
            return;
        }

        Schema::table('changelog_entries', function (Blueprint $table) {
            $table->string('excerpt', 300)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('changelog_entries', 'excerpt')) {
            return;
        }

        Schema::table('changelog_entries', function (Blueprint $table) {
            $table->dropColumn('excerpt');
        });
    }
};
