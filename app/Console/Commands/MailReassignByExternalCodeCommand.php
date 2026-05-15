<?php

namespace App\Console\Commands;

use App\Enums\MailDirection;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill: пересвязать письма с внешними маркерами (LZ-REQ-NNNN и др.)
 * на «правильного родителя» — самое раннее EmailMessage с тем же маркером
 * и непустым related_request_id (по id ASC).
 *
 * Алгоритм:
 *   1. Pre-pass: для каждого уникального маркера определить parent_request_id
 *      ОДИН раз — это самое раннее EmailMessage с этим маркером и не пустой
 *      related_request_id. Используем этот target для ВСЕХ писем с маркером
 *      (идемпотентно, без пинг-понга).
 *   2. Pass: для каждого письма с маркером, если current related_request_id
 *      != target — перепривязать (с учётом защит).
 *
 * Защиты (все по умолчанию ВКЛ):
 *   --keep-outbound   — не двигать наши исходящие (direction=outbound)
 *   --keep-active     — не двигать письма с current Request в активном статусе
 *                       (работа менеджера уже идёт, потеряем контекст)
 *   --only-if-newer   — не двигать письмо, чей id меньше id parent'а
 *                       (защита от обратной хронологии)
 *   --code=LZ-REQ-N   — точечный режим для одного маркера
 *
 *   php artisan mail:reassign-by-external-code                       # dry-run, все защиты
 *   php artisan mail:reassign-by-external-code --apply               # реально, с защитами
 *   php artisan mail:reassign-by-external-code --code=LZ-REQ-1182    # один маркер
 *   php artisan mail:reassign-by-external-code --no-keep-active      # снять защиту active
 */
class MailReassignByExternalCodeCommand extends Command
{
    protected $signature = 'mail:reassign-by-external-code
        {--apply : Реально перепривязать, иначе только показать что планируется}
        {--limit=0 : Ограничить число обработанных писем (0 = все)}
        {--code= : Точечный режим — обрабатывать только письма с этим маркером}
        {--keep-outbound=1 : Не двигать наши исходящие (default ON)}
        {--keep-active=1 : Не двигать письма привязанные к active Request (default ON)}
        {--only-if-newer=1 : Не двигать письма старше parent (default ON)}';

    protected $description = 'Перепривязать письма с external-маркерами (LZ-REQ-NNNN) к правильному родителю';

    private const ACTIVE_STATUSES = [
        RequestStatus::InProgress,
        RequestStatus::Assigned,
        RequestStatus::Quoted,
        RequestStatus::UnderReview,
        RequestStatus::AwaitingInvoice,
        RequestStatus::AwaitingClientClarification,
        RequestStatus::Invoiced,
        RequestStatus::Paid,
    ];

    public function handle(): int
    {
        $patterns = (array) config('services.mail.external_codes', []);
        if (empty($patterns)) {
            $this->warn('Нет паттернов в config(services.mail.external_codes).');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $targetCode = trim((string) $this->option('code'));
        $keepOutbound = (bool) $this->option('keep-outbound');
        $keepActive = (bool) $this->option('keep-active');
        $onlyIfNewer = (bool) $this->option('only-if-newer');

        // Шаг 1: pre-compute mapping code → earliest parent message.
        // Один запрос на маркер, потом применяем target ко всем письмам.
        $this->info('Шаг 1: сбор parent-mapping по маркерам…');
        $codeToParent = $this->buildCodeToParentMapping($patterns, $targetCode);
        $this->info('Найдено уникальных маркеров с parent: ' . count($codeToParent));

        if (empty($codeToParent)) {
            $this->warn('Не нашлось ни одного маркера с привязанным родителем. Нечего делать.');

            return self::SUCCESS;
        }

        $activeStatusValues = array_map(fn (RequestStatus $s) => $s->value, self::ACTIVE_STATUSES);

        // Шаг 2: пройти по всем письмам с маркером и решить про reassign.
        $this->info('Шаг 2: проход по письмам…');

        $query = EmailMessage::query()
            ->where(function ($q) {
                $q->where('subject', 'ilike', '%LZ-REQ-%')
                    ->orWhere('body_plain', 'ilike', '%LZ-REQ-%');
            })
            ->orderBy('id');

        if ($targetCode !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $targetCode) . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('subject', 'ilike', $needle)
                    ->orWhere('body_plain', 'ilike', $needle);
            });
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $changed = 0;
        $kept = 0;
        $skipOutbound = 0;
        $skipActive = 0;
        $skipNewer = 0;
        $orphan = 0;

        $query->chunkById(200, function ($messages) use (
            &$changed, &$kept, &$skipOutbound, &$skipActive, &$skipNewer, &$orphan,
            $codeToParent, $apply, $patterns, $keepOutbound, $keepActive, $onlyIfNewer,
            $activeStatusValues,
        ) {
            foreach ($messages as $msg) {
                $codes = $this->extractCodes((string) $msg->subject . "\n" . (string) $msg->body_plain, $patterns);
                if (empty($codes)) {
                    continue;
                }

                // target — самый ранний parent среди всех маркеров письма.
                $target = null;
                $targetCodeMatched = null;
                foreach (array_keys($codes) as $code) {
                    if (! isset($codeToParent[$code])) {
                        continue;
                    }
                    [$parentId, $parentRequestId] = $codeToParent[$code];
                    if ($target === null || $parentId < $target[0]) {
                        $target = [$parentId, $parentRequestId];
                        $targetCodeMatched = $code;
                    }
                }

                if ($target === null) {
                    $orphan++;
                    continue;
                }

                [$parentMsgId, $parentRequestId] = $target;

                if ($msg->related_request_id === $parentRequestId) {
                    $kept++;
                    continue;
                }

                // Защита: не двигаем outbound.
                if ($keepOutbound && $msg->direction === MailDirection::Outbound) {
                    $skipOutbound++;
                    continue;
                }

                // Защита: не двигаем письма привязанные к active Request.
                // Используем SQL `whereIn`, потому что value('status') в
                // Laravel 11+ возвращает enum object (cast в model), а не string.
                if ($keepActive && $msg->related_request_id !== null) {
                    $isActive = Request::query()
                        ->whereKey($msg->related_request_id)
                        ->whereIn('status', $activeStatusValues)
                        ->exists();
                    if ($isActive) {
                        $skipActive++;
                        continue;
                    }
                }

                // Защита: не двигаем письмо чей id меньше parent'а
                // (защита от обратной хронологии).
                if ($onlyIfNewer && $msg->id < $parentMsgId) {
                    $skipNewer++;
                    continue;
                }

                $from = $msg->related_request_id;
                $fromCode = $from ? Request::query()->whereKey($from)->value('internal_code') : 'NULL';
                $toCode = Request::query()->whereKey($parentRequestId)->value('internal_code') ?: '?';

                $this->line(sprintf(
                    '  #%d  [%s]  %s  →  %s',
                    $msg->id,
                    $targetCodeMatched,
                    $fromCode ?: 'NULL',
                    $toCode,
                ));

                if ($apply) {
                    $this->applyReassign($msg, $parentRequestId, $from, $codes);
                }
                $changed++;
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Итого: будет перепривязано=%d, без изменений=%d, осиротевших=%d',
            $changed,
            $kept,
            $orphan,
        ));
        $this->line(sprintf(
            'Пропущено защитами: outbound=%d, active-status=%d, only-newer=%d',
            $skipOutbound,
            $skipActive,
            $skipNewer,
        ));

        if (! $apply && $changed > 0) {
            $this->newLine();
            $this->line('Это dry-run. Запустите с --apply для реального изменения.');
        }

        return self::SUCCESS;
    }

    /**
     * Pre-pass: для каждого уникального external-маркера найти самое
     * раннее EmailMessage (по id ASC) с этим маркером и
     * related_request_id IS NOT NULL.
     *
     * @param  array<int, string>  $patterns
     * @return array<string, array{0:int, 1:int}>  code => [parent_msg_id, parent_request_id]
     */
    private function buildCodeToParentMapping(array $patterns, string $filterCode = ''): array
    {
        // Собираем все уникальные маркеры.
        $codes = [];

        $q = EmailMessage::query()
            ->whereNotNull('related_request_id')
            ->where(function ($w) {
                $w->where('subject', 'ilike', '%LZ-REQ-%')
                    ->orWhere('body_plain', 'ilike', '%LZ-REQ-%');
            });
        if ($filterCode !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filterCode) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('subject', 'ilike', $needle)
                    ->orWhere('body_plain', 'ilike', $needle);
            });
        }

        $q->orderBy('id')->chunkById(200, function ($messages) use (&$codes, $patterns) {
            foreach ($messages as $m) {
                $h = (string) $m->subject . "\n" . (string) $m->body_plain;
                foreach ($patterns as $p) {
                    if (preg_match_all($p, $h, $mm)) {
                        foreach (array_unique($mm[0]) as $code) {
                            // Уже встречали — пропускаем (этот msg НЕ самый ранний).
                            if (isset($codes[$code])) {
                                continue;
                            }
                            $codes[$code] = [$m->id, $m->related_request_id];
                        }
                    }
                }
            }
        });

        return $codes;
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array<string, true>
     */
    private function extractCodes(string $haystack, array $patterns): array
    {
        $codes = [];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $haystack, $m)) {
                foreach ($m[0] as $c) {
                    $codes[$c] = true;
                }
            }
        }

        return $codes;
    }

    /**
     * @param  array<string, true>  $codes
     */
    private function applyReassign(EmailMessage $msg, int $toRequestId, ?int $fromRequestId, array $codes): void
    {
        $existing = is_array($msg->detected_artifacts ?? null) ? $msg->detected_artifacts : [];
        $existing[] = [
            'type' => 'manual_reassign_by_external_code',
            'from_request_id' => $fromRequestId,
            'to_request_id' => $toRequestId,
            'codes' => array_keys($codes),
            'reassigned_at' => now()->toIso8601String(),
        ];

        $msg->forceFill([
            'related_request_id' => $toRequestId,
            'detected_artifacts' => $existing,
        ])->save();

        Log::info('mail:reassign-by-external-code', [
            'email_message_id' => $msg->id,
            'from_request_id' => $fromRequestId,
            'to_request_id' => $toRequestId,
            'codes' => array_keys($codes),
        ]);
    }
}
