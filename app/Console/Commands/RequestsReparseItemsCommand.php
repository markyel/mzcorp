<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request as ClientRequest;
use App\Services\Request\RequestItemPersister;
use App\Services\RequestItemParsingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Точечный re-parse позиций для конкретных заявок по internal_code.
 *
 * Когда нужно: парсер пострадал от регрессии (см. M-2026-1032, повторяющиеся
 * артикулы между двумя «счетами» в одном письме схлопывались до уникальных).
 * После фикса промпта rebake через `requests:parse-items --apply --force`
 * не помогает — `RequestItemPersister::filterNewItems` зарежет новые позиции
 * как дубли существующих по artice. Поэтому удаляем старые items и парсим
 * заново.
 *
 *   php artisan requests:reparse-items M-2026-1032
 *   php artisan requests:reparse-items M-2026-1032 M-2026-1063 --apply
 *
 * Без --apply печатает что собирается делать (dry-run).
 */
class RequestsReparseItemsCommand extends Command
{
    protected $signature = 'requests:reparse-items
        {codes* : internal_code заявок (например M-2026-1032)}
        {--apply : Реально удалить старые items и сохранить новые}';

    protected $description = 'Точечный re-parse: удалить items заявки и распарсить заново из исходного письма.';

    public function handle(
        RequestItemParsingService $parser,
        RequestItemPersister $persister,
    ): int {
        $codes = (array) $this->argument('codes');
        $apply = (bool) $this->option('apply');

        $this->info(sprintf(
            'Re-parse %d заявок (mode: %s)',
            count($codes),
            $apply ? 'APPLY' : 'dry-run',
        ));

        $stats = ['ok' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($codes as $code) {
            $req = ClientRequest::query()
                ->where('internal_code', $code)
                ->first();
            if (! $req) {
                $this->error("[$code] заявка не найдена");
                $stats['failed']++;
                continue;
            }

            $msg = $req->email_message_id
                ? EmailMessage::find($req->email_message_id)
                : null;
            if (! $msg) {
                $this->error("[$code] исходное письмо не найдено (request.email_message_id=null)");
                $stats['failed']++;
                continue;
            }

            $currentItems = $req->items()->count();
            $this->line(sprintf(
                '[%s] request #%d, email #%d (subject: «%s»). Текущих позиций: %d',
                $code,
                $req->id,
                $msg->id,
                mb_substr((string) $msg->subject, 0, 60),
                $currentItems,
            ));

            if (! $apply) {
                $this->warn("  → dry-run: позиции будут удалены и распарсены заново после --apply");
                $stats['skipped']++;
                continue;
            }

            try {
                $items = $parser->parseItemsFromInboundMessage($msg);
            } catch (\Throwable $e) {
                $this->error("  ✗ парсер упал: {$e->getMessage()}");
                Log::error('requests:reparse-items: parse failed', [
                    'internal_code' => $code,
                    'email_message_id' => $msg->id,
                    'error' => $e->getMessage(),
                ]);
                $stats['failed']++;
                continue;
            }

            if (empty($items)) {
                $this->warn("  ⚠ парсер вернул пустой список — не трогаем старые позиции");
                $stats['skipped']++;
                continue;
            }

            try {
                DB::transaction(function () use ($req, $msg, $items, $persister, $code) {
                    $deleted = $req->items()->delete();
                    $this->line("  - удалено старых позиций: {$deleted}");

                    $result = $persister->persist($msg, $items);
                    $this->info(sprintf(
                        '  ✓ добавлено новых: %d (dup ignored: %d)',
                        $result['new'],
                        $result['dup'],
                    ));
                });
                $stats['ok']++;
            } catch (\Throwable $e) {
                $this->error("  ✗ persist упал: {$e->getMessage()}");
                Log::error('requests:reparse-items: persist failed', [
                    'internal_code' => $code,
                    'email_message_id' => $msg->id,
                    'error' => $e->getMessage(),
                ]);
                $stats['failed']++;
            }
        }

        $this->newLine();
        $this->table(
            ['metric', 'value'],
            collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all(),
        );

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
