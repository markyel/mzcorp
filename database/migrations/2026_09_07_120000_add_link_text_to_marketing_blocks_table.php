<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Свой текст ссылки в рекламном блоке («Подробнее →» по умолчанию):
 * «Смотреть каталог», «Узнать цену», «Скачать прайс» и т.п.
 * NULL/пусто — используется MarketingBlock::DEFAULT_LINK_TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_blocks') || Schema::hasColumn('marketing_blocks', 'link_text')) {
            return;
        }

        Schema::table('marketing_blocks', function (Blueprint $t) {
            $t->string('link_text', 60)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_blocks') && Schema::hasColumn('marketing_blocks', 'link_text')) {
            Schema::table('marketing_blocks', function (Blueprint $t) {
                $t->dropColumn('link_text');
            });
        }
    }
};
