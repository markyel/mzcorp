<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Одноразовая миграция timestamps UTC → MSK.
 *
 * Контекст: до 2026-05-22 проект работал с `APP_TIMEZONE=UTC` (default Laravel).
 * PHP писал в БД `now()` UTC-числами. Postgres был в `Europe/Moscow` —
 * NOW() возвращал MSK, что давало рассинхрон между Postgres-side и PHP-side
 * сравнениями.
 *
 * Решение: сменить APP_TIMEZONE на `Europe/Moscow` + сдвинуть существующие
 * timestamps +3ч (UTC → MSK).
 *
 *   php artisan db:shift-timestamps-utc-to-msk            # dry-run
 *   php artisan db:shift-timestamps-utc-to-msk --apply    # выполнить
 *
 * ВАЖНО: запускать ОДИН РАЗ под maintenance — после смены APP_TIMEZONE
 * в .env и до пересборки config-cache. Повторный запуск сдвинет данные
 * ещё на 3 часа.
 */
class DbShiftTimestampsUtcToMskCommand extends Command
{
    protected $signature = 'db:shift-timestamps-utc-to-msk
        {--apply : Реально применить UPDATE, иначе dry-run}
        {--hours=3 : На сколько часов сдвигать (default 3 для UTC→MSK)}';

    protected $description = 'Сдвинуть все timestamp/datetime столбцы +N часов (миграция UTC → MSK)';

    /**
     * Какие столбцы где сдвигать. Перечислены явно — чтобы случайно не
     * задеть колонки не-timestamp типа (имена могут совпадать). Только
     * колонки которые писались через Eloquent с now() / Carbon-объектами.
     *
     * @var array<string, array<int, string>>
     */
    private const TABLES = [
        'requests' => [
            'created_at', 'updated_at', 'assigned_at', 'closed_at',
            'attention_required_at', 'last_activity_at', 'paused_until',
            'reanimated_at', 'merged_at',
        ],
        'request_items' => ['created_at', 'updated_at'],
        'request_assignments' => ['created_at', 'updated_at', 'assigned_at'],
        'request_state_changes' => ['created_at'],
        'request_delegations' => ['created_at', 'updated_at', 'started_at', 'ended_at'],
        'request_user_views' => ['last_seen_at', 'created_at', 'updated_at'],
        'email_messages' => [
            'created_at', 'updated_at', 'sent_at',
            'categorized_at', 'classified_at',
        ],
        'email_attachments' => ['created_at', 'updated_at'],
        'mailboxes' => ['created_at', 'updated_at', 'last_sync_at', 'token_expires_at'],
        'mail_routing_rules' => ['created_at', 'updated_at'],
        'routed_mails' => ['created_at', 'processed_at'],
        'ai_decisions' => ['created_at', 'updated_at', 'applied_at'],
        'clarification_batches' => [
            'created_at', 'updated_at', 'sent_at', 'answered_at',
        ],
        'clarification_questions' => ['created_at', 'updated_at', 'answered_at'],
        'inbound_url_fetches' => ['created_at', 'updated_at'],
        'users' => ['created_at', 'updated_at', 'unavailable_until', 'unavailable_from'],
        'notifications' => ['created_at', 'updated_at', 'read_at'],
        'sessions' => [],
        'jobs' => [],
        'failed_jobs' => ['failed_at'],
        'cache' => [],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $hours = (int) $this->option('hours');
        $interval = "INTERVAL '{$hours} hours'";

        $this->info(sprintf(
            'Сдвиг timestamps: %s часов. Режим: %s',
            $hours,
            $apply ? 'APPLY' : 'DRY-RUN',
        ));
        $this->newLine();

        $totalRows = 0;
        $totalTables = 0;
        $totalColumns = 0;

        foreach (self::TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->line("· skip: таблицы {$table} нет");

                continue;
            }
            if (empty($columns)) {
                continue;
            }

            $tableLines = [];
            foreach ($columns as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    $tableLines[] = sprintf('   · %s: колонки нет — skip', $col);

                    continue;
                }

                $cnt = DB::table($table)->whereNotNull($col)->count();
                $tableLines[] = sprintf('   · %s: %d rows', $col, $cnt);

                if ($apply && $cnt > 0) {
                    DB::statement(
                        "UPDATE \"{$table}\" SET \"{$col}\" = \"{$col}\" + {$interval} WHERE \"{$col}\" IS NOT NULL"
                    );
                }
                $totalRows += $cnt;
                $totalColumns++;
            }

            if (! empty($tableLines)) {
                $this->line($table . ':');
                foreach ($tableLines as $l) {
                    $this->line($l);
                }
                $totalTables++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Итого: %d таблиц, %d столбцов, %d row-обновлений.',
            $totalTables,
            $totalColumns,
            $totalRows,
        ));

        if (! $apply) {
            $this->newLine();
            $this->line('Это dry-run. Запустите с --apply под maintenance window.');
            $this->line('ВАЖНО: применять ОДИН РАЗ. Повторный запуск сдвинет данные ещё на ' . $hours . ' часов.');
        }

        return self::SUCCESS;
    }
}
