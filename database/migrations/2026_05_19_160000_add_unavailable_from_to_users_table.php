<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation Фаза 2 — планируемая недоступность.
 *
 * РОП может заранее запланировать отсутствие менеджера. Поля:
 *
 *  - unavailable_from — момент НАЧАЛА периода отсутствия. NULL = с момента
 *    сохранения (то же что текущая логика — «недоступен прямо сейчас»).
 *    Если > now() — будущее планирование, до этого момента менеджер
 *    в available().
 *
 *  - unavailable_auto_delegate — bool, default false. Флаг «открыть
 *    активные заявки коллегам автоматически в момент начала отсутствия».
 *    Используется cron'ом `users:apply-planned-unavailability` который
 *    при наступлении unavailable_from дёргает delegateActiveRequests.
 *    Если false — РОП должен будет вручную открыть заявки коллегам
 *    в день начала.
 *
 * Логика scope `available()`:
 *   available iff
 *     archived_at IS NULL
 *     AND (unavailable_until IS NULL
 *          OR unavailable_until <= now()
 *          OR unavailable_from > now())
 *
 * Покрывает 4 кейса:
 *  - Никаких полей не выставлено → available
 *  - Период уже прошёл (until <= now) → available (вернулся)
 *  - Период ещё не наступил (from > now) → available (планирование)
 *  - Период идёт (from IS NULL OR from <= now, until > now) → НЕ available
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'unavailable_from')) {
                $table->timestamp('unavailable_from')->nullable()->after('unavailable_until');
            }
            if (! Schema::hasColumn('users', 'unavailable_auto_delegate')) {
                $table->boolean('unavailable_auto_delegate')->default(false)->after('unavailable_from');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['unavailable_auto_delegate', 'unavailable_from'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
