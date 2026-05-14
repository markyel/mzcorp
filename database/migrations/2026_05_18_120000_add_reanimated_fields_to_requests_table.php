<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation §5.2 — реанимация closed_lost заявок.
 *
 * Если клиент написал после «тихого» закрытия (no_client_response_to_*) —
 * MyLift не создаёт дубликат, а возвращает заявку в работу. Поля:
 *
 *  - reanimated_at — timestamp последней реанимации. NULL = никогда не
 *    реанимировалась (свежая или продолжает первый цикл). UI Hero рендерит
 *    чип «Реанимирована N дн. назад» если поле непустое.
 *  - reanimated_count — счётчик циклов реанимации. Используется для
 *    предупреждения «реанимирована уже N раз — стоит подумать перед
 *    очередным закрытием».
 *
 * История сохраняется в `request_state_changes.payload` для каждого
 * reanimate-перехода (восстановленные closed_at / closed_lost_reason /
 * closed_lost_quote + source_email_message_id).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'reanimated_at')) {
                $table->timestamp('reanimated_at')->nullable()->after('closed_lost_source_message_id');
            }
            if (! Schema::hasColumn('requests', 'reanimated_count')) {
                $table->smallInteger('reanimated_count')->default(0)->after('reanimated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            foreach (['reanimated_count', 'reanimated_at'] as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
