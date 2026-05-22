<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Агрегированная мета по парсингу заявки:
 *  - `dedup_dropped`: сводка всех схлопнутых дублей (per attachment / per source).
 *  - `attachment_extracted`: справочная информация, извлечённая из вложений
 *    помимо позиций — серийник лифта, модель, серия, объект, контактное лицо,
 *    номер договора, желаемая дата, ссылки на схемы. Заполняется
 *    AttachmentMetaExtractionService.
 *
 * Структура:
 *   {
 *     "dedup_dropped": [
 *       {
 *         "source": "xlsx_attachment_2384",
 *         "row": 9,
 *         "name": "...",
 *         "article": "...",
 *         "qty": "1",
 *         "merged_into_position": 9,
 *         "reason": "same_normalized_article_qty_inv",
 *         "at": "2026-05-22T18:00:00+03:00"
 *       }
 *     ],
 *     "attachment_extracted": [
 *       {
 *         "source": "xlsx_attachment_2384",
 *         "filename": "Для заказа по позициям.xlsx",
 *         "fields": {
 *           "lift_serial": "7909814",
 *           "lift_model": "KONE 3000",
 *           "object_address": "...",
 *           "contract_number": "ABC-123",
 *           "desired_date": "2026-06-01",
 *           "contact_person": "...",
 *           "links": ["https://..."]
 *         },
 *         "at": "2026-05-22T18:00:00+03:00"
 *       }
 *     ]
 *   }
 *
 * Это НАДЗАЯВОЧНЫЙ snapshot, отдельный от `request_context` (KB-граф) и
 * от `request_state_changes` (audit-журнал). UI карточки заявки рисует
 * на его основе баннер во вкладке Позиции и блок «Справочно из файлов»
 * на Overview.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'parsing_meta')) {
                $table->jsonb('parsing_meta')->nullable()->after('complexity_level');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'parsing_meta')) {
                $table->dropColumn('parsing_meta');
            }
        });
    }
};
