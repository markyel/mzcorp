<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Services\Request\RequestItemPersister;
use App\Services\RequestItemParsingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.8b: запуск content-driven парсера позиций на inbound-почте.
 *
 *   php artisan requests:parse-items 123              # одно письмо, dry-run print
 *   php artisan requests:parse-items 123 --apply      # одно письмо + persist
 *   php artisan requests:parse-items --limit=10       # bulk dry-run, 10 шт.
 *   php artisan requests:parse-items --apply --limit=500   # full backfill
 *   php artisan requests:parse-items --apply --force  # перепарсить уже обработанные
 *
 * В bulk-режиме по умолчанию пропускает письма, чей Request уже имеет items
 * (стоимость: каждый вызов — GPT-4.1 + Vision).
 */
class RequestsParseItemsCommand extends Command
{
    protected $signature = 'requests:parse-items
        {message? : EmailMessage id (single mode) или omit для bulk}
        {--apply : Сохранить items + создать/обновить Request}
        {--limit=20 : Bulk: максимум писем за прогон}
        {--from-id=0 : Bulk: пропустить письма с id ниже}
        {--force : Bulk: перепарсить даже письма с уже непустым Request->items}
        {--reset : При --apply удалить существующие RequestItem-ы перед persist (clean reparse)}';

    protected $description = 'Phase 1.8b: распарсить позиции из inbound-писем (Vision/text).';

    public function handle(
        RequestItemParsingService $parser,
        RequestItemPersister $persister,
    ): int {
        $apply = (bool) $this->option('apply');

        if ($id = $this->argument('message')) {
            return $this->processSingle((int) $id, $parser, $persister, $apply);
        }

        return $this->processBulk($parser, $persister, $apply);
    }

    private function processSingle(
        int $id,
        RequestItemParsingService $parser,
        RequestItemPersister $persister,
        bool $apply,
    ): int {
        $msg = EmailMessage::find($id);
        if (! $msg) {
            $this->error("EmailMessage #{$id} не найден.");

            return self::FAILURE;
        }

        try {
            $items = $parser->parseItemsFromInboundMessage($msg);
        } catch (\Throwable $e) {
            $this->error("Парсинг провалился: {$e->getMessage()}");
            Log::error('requests:parse-items single failure', [
                'email_message_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->renderItems($msg, $items);

        if ($apply) {
            if (empty($items)) {
                $this->warn('  Items пустые — Request не создаётся.');

                return self::SUCCESS;
            }

            // --reset: чистый перепарсинг — удалить старые items перед persist.
            // Имеет смысл только если письмо уже привязано к Request; для нового
            // письма удалять нечего.
            if ($this->option('reset') && $msg->related_request_id) {
                $deleted = \App\Models\RequestItem::query()
                    ->where('request_id', $msg->related_request_id)
                    ->delete();
                $this->warn("  --reset: удалено старых позиций: {$deleted}");
            }

            $result = $persister->persist($msg, $items);
            $req = $result['request'];
            $this->info(sprintf(
                '  Saved → Request %s (id=%d): +%d новых позиций, %d дубликатов, %s.',
                $req->internal_code,
                $req->id,
                $result['new'],
                $result['dup'],
                $result['just_created'] ? 'created' : 'updated',
            ));
        } elseif (! empty($items)) {
            $this->line('');
            $this->warn('  Запустите с --apply, чтобы создать Request + RequestItems.');
        }

        return self::SUCCESS;
    }

    private function processBulk(
        RequestItemParsingService $parser,
        RequestItemPersister $persister,
        bool $apply,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $fromId = (int) $this->option('from-id');
        $force = (bool) $this->option('force');

        $query = EmailMessage::query()
            ->where('direction', 'inbound')
            ->where('id', '>=', $fromId)
            ->orderBy('id');

        if (! $force) {
            // Skip: у которых уже есть Request с непустым items.
            $query->whereNotIn('id', function ($sub) {
                $sub->select('email_message_id')
                    ->from('requests')
                    ->whereExists(function ($s2) {
                        $s2->selectRaw('1')->from('request_items')
                            ->whereColumn('request_items.request_id', 'requests.id');
                    });
            });
        }

        $messages = $query->limit($limit)->get();
        if ($messages->isEmpty()) {
            $this->info('Нет писем для обработки.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Обрабатываю %d писем (mode: %s, force: %s)...',
            $messages->count(),
            $apply ? 'APPLY' : 'dry-run',
            $force ? 'yes' : 'no',
        ));

        $stats = [
            'parsed' => 0,
            'with_items' => 0,
            'total_items' => 0,
            'requests_created' => 0,
            'requests_updated' => 0,
            'failed' => 0,
        ];

        $progress = $this->output->createProgressBar($messages->count());
        $progress->start();

        foreach ($messages as $m) {
            try {
                $items = $parser->parseItemsFromInboundMessage($m);
                $stats['parsed']++;

                if (! empty($items)) {
                    $stats['with_items']++;
                    $stats['total_items'] += count($items);

                    if ($apply) {
                        if ($this->option('reset') && $m->related_request_id) {
                            \App\Models\RequestItem::query()
                                ->where('request_id', $m->related_request_id)
                                ->delete();
                        }
                        $result = $persister->persist($m, $items);
                        if ($result['just_created']) {
                            $stats['requests_created']++;
                        } else {
                            $stats['requests_updated']++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error('requests:parse-items bulk failure', [
                    'email_message_id' => $m->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $progress->advance();
        }

        $progress->finish();
        $this->newLine();

        $this->table(
            ['metric', 'value'],
            collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all(),
        );

        if (! $apply && $stats['with_items'] > 0) {
            $this->line('');
            $this->warn('Запустите с --apply, чтобы создать Request + RequestItems.');
        }

        return self::SUCCESS;
    }

    private function renderItems(EmailMessage $msg, array $items): void
    {
        $this->line('');
        $this->line(sprintf(
            'email#%d  ← %s  «%s»',
            $msg->id,
            $msg->from_email ?: '(no from)',
            mb_substr((string) $msg->subject, 0, 60),
        ));

        if (empty($items)) {
            $this->warn('  → items пустые (не заявка)');

            return;
        }

        $rows = [];
        foreach ($items as $i => $it) {
            $rows[] = [
                (string) ($i + 1),
                mb_substr((string) ($it['name'] ?? ''), 0, 60),
                (string) ($it['brand'] ?? ''),
                (string) ($it['article'] ?? ''),
                (string) ($it['qty'] ?? ''),
                (string) ($it['unit'] ?? ''),
                mb_substr((string) ($it['note'] ?? ''), 0, 25),
            ];
        }
        $this->table(['#', 'name', 'brand', 'article', 'qty', 'unit', 'note'], $rows);
    }
}
