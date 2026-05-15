<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill: пересвязать письма с внешними маркерами (LZ-REQ-NNNN и др.)
 * на «правильного родителя» — самое раннее EmailMessage с тем же маркером
 * и непустым related_request_id.
 *
 * Появилась после введения Level 3.5 matchByExternalCode в InboundReplyLinker
 * (commit 33a3252) — для тех писем, что пришли ДО фикса и попали к не своим
 * заявкам через Level 4 fallback.
 *
 *   php artisan mail:reassign-by-external-code            # dry-run
 *   php artisan mail:reassign-by-external-code --apply    # реально меняет
 *   php artisan mail:reassign-by-external-code --apply --limit=50
 */
class MailReassignByExternalCodeCommand extends Command
{
    protected $signature = 'mail:reassign-by-external-code
        {--apply : Реально перепривязать, иначе только показать что планируется}
        {--limit=0 : Ограничить число обработанных писем (0 = все)}';

    protected $description = 'Перепривязать письма с external-маркерами (LZ-REQ-NNNN) к правильному родителю';

    public function handle(): int
    {
        $patterns = (array) config('services.mail.external_codes', []);
        if (empty($patterns)) {
            $this->warn('Нет паттернов в config(services.mail.external_codes). Нечего делать.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));

        // Собираем все письма, в subject/body которых есть хотя бы один маркер.
        // Используем общий ILIKE по 'LZ-REQ-' (упрощение для текущих паттернов).
        // Если паттерны станут разнородными — переделать.
        $query = EmailMessage::query()
            ->where(function ($q) {
                $q->where('subject', 'ilike', '%LZ-REQ-%')
                    ->orWhere('body_plain', 'ilike', '%LZ-REQ-%');
            })
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info("Найдено писем с external-маркерами: {$total}");

        if ($limit > 0) {
            $query->limit($limit);
            $this->info("Лимит обработки: {$limit}");
        }

        $changed = 0;
        $kept = 0;
        $orphan = 0;
        $noCode = 0;

        $query->chunkById(200, function ($messages) use (&$changed, &$kept, &$orphan, &$noCode, $apply, $patterns) {
            foreach ($messages as $msg) {
                $codes = $this->extractCodes((string) $msg->subject . "\n" . (string) $msg->body_plain, $patterns);
                if (empty($codes)) {
                    $noCode++;

                    continue;
                }

                $parentRequestId = $this->findParentRequestId($msg, $codes);
                if ($parentRequestId === null) {
                    $orphan++;
                    continue;
                }

                if ($msg->related_request_id === $parentRequestId) {
                    $kept++;
                    continue;
                }

                $from = $msg->related_request_id;
                $fromCode = $from ? Request::query()->whereKey($from)->value('internal_code') : 'NULL';
                $toCode = Request::query()->whereKey($parentRequestId)->value('internal_code') ?: '?';

                $codesStr = implode(',', array_keys($codes));
                $this->line(sprintf(
                    '  #%d  [%s]  %s  →  %s',
                    $msg->id,
                    $codesStr,
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
            'Итого: будет перепривязано=%d, оставлено как есть=%d, осиротевших=%d, без маркера=%d',
            $changed,
            $kept,
            $orphan,
            $noCode,
        ));

        if (! $apply && $changed > 0) {
            $this->line('');
            $this->line('Это был dry-run. Запустите с --apply для реального изменения.');
        }

        return self::SUCCESS;
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
     * Найти request_id «правильного родителя» — самое раннее EmailMessage
     * с тем же маркером и непустым related_request_id (исключая сам $msg).
     *
     * @param  array<string, true>  $codes
     */
    private function findParentRequestId(EmailMessage $msg, array $codes): ?int
    {
        $best = null;
        foreach (array_keys($codes) as $code) {
            $parent = EmailMessage::query()
                ->whereNotNull('related_request_id')
                ->where('id', '!=', $msg->id)
                ->where(function ($q) use ($code) {
                    $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $code) . '%';
                    $q->where('subject', 'ilike', $needle)
                        ->orWhere('body_plain', 'ilike', $needle);
                })
                ->orderBy('id')
                ->first(['id', 'related_request_id']);

            if ($parent === null) {
                continue;
            }
            if ($best === null || $parent->id < $best->id) {
                $best = $parent;
            }
        }

        return $best?->related_request_id;
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
