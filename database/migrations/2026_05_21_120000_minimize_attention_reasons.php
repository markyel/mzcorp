<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Минимизация набора attention_reason до 3 значений: sla_breach,
 * postponed_resume, client_replied.
 *
 * Phase 1.11 имел 7 значений (awaiting_client / awaiting_supplier /
 * quote_followup_due / invoice_followup_due / postponed_resume /
 * partial_quote_overdue / sla_breach). Полевая обратная связь:
 * «Жду клиента», «Нудж по КП», «Нудж по счёту» — рабочие фазы заявки, а
 * не сигнал «нужно действие сейчас». Подсветка в Pool рассеивала внимание.
 *
 * Что делает миграция:
 *  - Сбрасывает attention_required_at / attention_reason / attention_level
 *    у заявок с deprecated reason'ом (awaiting_client, quote_followup_due,
 *    invoice_followup_due, awaiting_supplier, partial_quote_overdue).
 *  - При следующем cron-tick `requests:check-attention` ИЛИ при ближайшем
 *    transitionTo() AttentionService::recompute() пересчитает по новой
 *    логике (compute() для этих же статусов вернёт SlaBreach с тем же
 *    дедлайном).
 *
 * Enum-кейсы в App\Enums\AttentionReason оставлены для совместимости с
 * любыми остаточными ссылками — но AttentionService::compute() их больше
 * не возвращает.
 */
return new class extends Migration
{
    private const DEPRECATED_REASONS = [
        'awaiting_client',
        'awaiting_supplier',
        'quote_followup_due',
        'invoice_followup_due',
        'partial_quote_overdue',
    ];

    public function up(): void
    {
        DB::table('requests')
            ->whereIn('attention_reason', self::DEPRECATED_REASONS)
            ->update([
                'attention_required_at' => null,
                'attention_reason' => null,
                'attention_level' => 0,
            ]);
    }

    public function down(): void
    {
        // Без обратной операции: исторические reason'ы восстановить нечем
        // (исходные дедлайны и логика выбора reason — в коде, не в БД).
        // На следующем cron-tick attention пересчитается по новой логике —
        // это эффективно «деградация без потерь» для пользователя.
    }
};
