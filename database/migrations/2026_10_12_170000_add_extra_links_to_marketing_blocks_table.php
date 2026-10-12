<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дополнительные ссылки рекламного блока рядом с основной: например,
 * «Написать в Telegram →» и «Написать в MAX →». Список [{url, text}];
 * NULL — только основная ссылка (url + link_text).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_blocks') || Schema::hasColumn('marketing_blocks', 'extra_links')) {
            return;
        }

        Schema::table('marketing_blocks', function (Blueprint $t) {
            $t->jsonb('extra_links')->nullable()->after('link_text');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_blocks') && Schema::hasColumn('marketing_blocks', 'extra_links')) {
            Schema::table('marketing_blocks', function (Blueprint $t) {
                $t->dropColumn('extra_links');
            });
        }
    }
};
