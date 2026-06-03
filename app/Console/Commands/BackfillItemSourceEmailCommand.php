<?php

namespace App\Console\Commands;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфилл провенанса позиция → письмо-источник для исторических данных.
 *
 * Колонка `request_items.source_email_message_id` заполняется парсером только
 * для позиций, созданных ПОСЛЕ её ввода. Для старых заявок восстанавливаем
 * источник эвристикой по времени:
 *
 *   для каждой позиции с NULL source_email_message_id берём входящее письмо
 *   этой заявки (direction=inbound, related_request_id=request) с максимальным
 *   sent_at <= request_items.created_at — парсинг запускается сразу после
 *   прихода письма, поэтому ближайшее предшествующее входящее и есть источник.
 *   Fallback — seed-письмо заявки (requests.email_message_id).
 *
 * Эвристика приблизительная (массовые правки/реимпорт могут сдвинуть время) —
 * поэтому гибридный UI разъединения позволяет админу скорректировать выбор.
 *
 * Usage:
 *   php artisan requests:backfill-item-source-email --dry-run
 *   php artisan requests:backfill-item-source-email --apply
 *   php artisan requests:backfill-item-source-email --apply --request=M-2026-1752
 */
class BackfillItemSourceEmailCommand extends Command
{
    protected $signature = 'requests:backfill-item-source-email
        {--dry-run : Показать, что будет проставлено, без записи}
        {--apply : Применить изменения}
        {--request= : Ограничить одной заявкой по internal_code}';

    protected $description = 'Бэкфилл source_email_message_id у позиций по времени (для исторических заявок).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if ($dryRun) {
            $this->info('DRY-RUN: изменения НЕ применяются. Для записи укажите --apply.');
        }

        $query = Request::query()->whereHas('items', function ($q) {
            $q->whereNull('source_email_message_id');
        });
        if ($code = $this->option('request')) {
            $query->where('internal_code', $code);
        }

        $totalItems = 0;
        $resolvedByInbound = 0;
        $resolvedBySeed = 0;
        $unresolved = 0;

        $query->orderBy('id')->chunkById(100, function ($requests) use (
            $apply, &$totalItems, &$resolvedByInbound, &$resolvedBySeed, &$unresolved
        ) {
            foreach ($requests as $request) {
                // Входящие письма заявки, отсортированы по sent_at ASC.
                // Исключаем cross-mailbox копии (тот же Message-ID, доставленный
                // в личный ящик менеджера) — как в треде карточки, чтобы
                // провенанс указывал на ВИДИМОЕ письмо, а не на скрытую копию.
                $inbound = EmailMessage::query()
                    ->where('related_request_id', $request->id)
                    ->where('direction', MailDirection::Inbound->value)
                    ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
                    ->orderBy('sent_at')
                    ->get(['id', 'sent_at']);

                $items = RequestItem::query()
                    ->where('request_id', $request->id)
                    ->whereNull('source_email_message_id')
                    ->orderBy('id')
                    ->get(['id', 'created_at']);

                foreach ($items as $item) {
                    $totalItems++;
                    $sourceId = null;

                    // Ближайшее входящее с sent_at <= item.created_at.
                    foreach ($inbound as $msg) {
                        if ($msg->sent_at !== null && $item->created_at !== null
                            && $msg->sent_at->lessThanOrEqualTo($item->created_at)) {
                            $sourceId = $msg->id;
                        }
                    }

                    if ($sourceId !== null) {
                        $resolvedByInbound++;
                    } elseif ($request->email_message_id !== null) {
                        $sourceId = $request->email_message_id; // fallback: seed
                        $resolvedBySeed++;
                    } else {
                        $unresolved++;
                        continue;
                    }

                    $this->line(sprintf(
                        '  %s · item #%d (created %s) → email #%d',
                        $request->internal_code,
                        $item->id,
                        optional($item->created_at)->format('Y-m-d H:i') ?? '?',
                        $sourceId,
                    ));

                    if ($apply) {
                        DB::table('request_items')
                            ->where('id', $item->id)
                            ->update(['source_email_message_id' => $sourceId]);
                    }
                }
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Позиций обработано: %d · по входящему: %d · по seed: %d · не определено: %d',
            $totalItems, $resolvedByInbound, $resolvedBySeed, $unresolved,
        ));
        if ($dryRun && $totalItems > 0) {
            $this->warn('Это был DRY-RUN. Запустите с --apply, чтобы записать.');
        }

        return self::SUCCESS;
    }
}
