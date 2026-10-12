<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Откуда пришла заявка, если не из почты: telegram | max (боты заявок).
 * NULL — обычная заявка из письма. messenger_chat_id — клиент бота.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'source')) {
                $table->string('source', 16)->nullable()->index();
            }
            if (! Schema::hasColumn('requests', 'messenger_chat_id')) {
                $table->foreignId('messenger_chat_id')->nullable()->index()->constrained('messenger_chats')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'messenger_chat_id')) {
                $table->dropConstrainedForeignId('messenger_chat_id');
            }
            if (Schema::hasColumn('requests', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
