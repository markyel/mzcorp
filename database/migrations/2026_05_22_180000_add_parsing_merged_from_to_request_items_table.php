<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Трасса дедупа: `parsing_merged_from` хранит список «съеденных» дублей
 * для победившей позиции.
 *
 * Заполняется в RequestItemParsingService::dedupeWithinList() (внутри
 * списка LLM) и пробрасывается через RequestItemPersister в БД. Менеджер
 * видит в UI карточки «эта позиция собрана из строк 8 и 9 xlsx» и может
 * принять решение — оставить как есть, расщепить руками, уточнить
 * у клиента.
 *
 * Формат:
 *   [
 *     {
 *       "source": "xlsx_attachment_2384" | "email_body" | "vision_photo",
 *       "name": "Вызывная панель в сборе (КОНЕЧНЫЕ ЭТАЖИ)",
 *       "article": "KM857841HXX,...",
 *       "qty": "1",
 *       "reason": "same_normalized_article_qty_inv",
 *       "dedup_key": "KM857841HXX...|qty=1|inv=1"
 *     }
 *   ]
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'parsing_merged_from')) {
                $table->jsonb('parsing_merged_from')->nullable()->after('match_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'parsing_merged_from')) {
                $table->dropColumn('parsing_merged_from');
            }
        });
    }
};
