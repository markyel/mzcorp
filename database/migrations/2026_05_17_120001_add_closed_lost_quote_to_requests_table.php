<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation §7.4: при переходе в closed_lost из inbound-classifier
 * сохраняем точную цитату из письма клиента + ссылку на это письмо.
 *
 * Используется на дашборде директората для аналитики «структура отказов»
 * и в будущем для AI-кластеризации новых паттернов причин.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'closed_lost_quote')) {
                $table->text('closed_lost_quote')->nullable()->after('closed_lost_comment');
            }
            if (! Schema::hasColumn('requests', 'closed_lost_source_message_id')) {
                $table->foreignId('closed_lost_source_message_id')
                    ->nullable()
                    ->after('closed_lost_quote')
                    ->constrained('email_messages')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'closed_lost_source_message_id')) {
                $table->dropConstrainedForeignId('closed_lost_source_message_id');
            }
            if (Schema::hasColumn('requests', 'closed_lost_quote')) {
                $table->dropColumn('closed_lost_quote');
            }
        });
    }
};
