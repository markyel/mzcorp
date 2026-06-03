<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Провенанс позиция → письмо-источник (для ручного разъединения заявок).
 *
 * До сих пор `request_items` не хранил, из какого письма позиция была
 * спаршена: `suggestion_source_email_id` заполнялся только для pending
 * reply-suggestion'ов, а seed/авто-применённые позиции имели NULL.
 *
 * Для механизма Split (вынос писем + их позиций в отдельную заявку, когда
 * linker ошибочно склеил два потока) нужен надёжный source-email у КАЖДОЙ
 * позиции. Заполняется в RequestItemPersister при парсинге (всегда =
 * message->id) и бэкфиллится по времени для исторических данных
 * (requests:backfill-item-source-email).
 *
 * Без жёсткого FK: письма не удаляются физически, а partial-history (старые
 * заявки до бэкфилла) может ссылаться на письма вне текущей выборки.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'source_email_message_id')) {
                $table->unsignedBigInteger('source_email_message_id')
                    ->nullable()
                    ->after('parsing_merged_from')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'source_email_message_id')) {
                $table->dropColumn('source_email_message_id');
            }
        });
    }
};
