<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email-дайджест ответов по обращениям вместо письма на каждый комментарий
 * (жалоба: по тикету #70 автору пришло 3 почти одинаковых письма подряд).
 *
 * emailed_at — «этот ответ уже ушёл получателю почтой» (в составе дайджеста
 * или письма «обращение решено»). NULL = ждёт ближайшего прогона
 * support:email-pending-replies.
 *
 * Backfill: вся история штампуется как отправленная, чтобы первый прогон
 * крона не разослал старые ответы заново.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('support_ticket_messages', 'emailed_at')) {
                $table->timestamp('emailed_at')->nullable()->index();
            }
        });

        DB::table('support_ticket_messages')
            ->whereNull('emailed_at')
            ->update(['emailed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            if (Schema::hasColumn('support_ticket_messages', 'emailed_at')) {
                $table->dropColumn('emailed_at');
            }
        });
    }
};
