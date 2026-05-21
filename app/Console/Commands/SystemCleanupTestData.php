<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Зачистка операционных данных перед боевым запуском.
 *
 * УДАЛЯЕТ:
 *   · Все операционные данные (requests, items, assignments, state_changes,
 *     delegations, views, context, email_messages + attachments,
 *     clarification_batches + questions, ai_decisions, routed_mails,
 *     outbound_quotes + items, quotations + items, mailbox_folder_states,
 *     inbound_url_fetches, notifications, jobs, failed_jobs).
 *   · Всех пользователей БЕЗ роли director (включая их роли в spatie).
 *   · Их личные ящики (mailboxes с owner_user_id = удаляемый user).
 *
 * ДЕАКТИВИРУЕТ (НЕ УДАЛЯЕТ):
 *   · Shared-ящики (без owner_user_id) — is_active = false.
 *
 * СОХРАНЯЕТ:
 *   · catalog_items / embeddings / imports — каталог
 *   · kb_* / equipment_* / manufacturer_* / identification_* / parameter_extractors
 *   · mail_routing_rules — правила маршрутизации
 *   · dealer_emails — справочник дилеров
 *   · app_settings — настройки
 *   · request_code_sequences — счётчик кодов
 *   · users с ролью `director` (включая их mailbox если есть)
 *   · permissions/roles (Spatie таблицы)
 *
 * Использование:
 *   php artisan system:cleanup-test-data                 # DRY RUN
 *   php artisan system:cleanup-test-data --apply         # выполнить
 */
class SystemCleanupTestData extends Command
{
    protected $signature = 'system:cleanup-test-data
                            {--apply : Реально выполнить удаление (без флага — dry-run)}';

    protected $description = 'Зачистка операционных данных перед боевым запуском (сохраняет директораты + справочники)';

    /**
     * Операционные таблицы в порядке удаления.
     *
     * @var array<int, array{table: string, label: string}>
     */
    private const WIPE_ORDER = [
        ['table' => 'request_user_views', 'label' => 'Просмотры заявок'],
        ['table' => 'request_delegations', 'label' => 'Делегирования заявок'],
        ['table' => 'request_state_changes', 'label' => 'История смены статусов'],
        ['table' => 'request_assignments', 'label' => 'Назначения менеджеров'],
        ['table' => 'request_items', 'label' => 'Позиции заявок'],
        ['table' => 'request_context', 'label' => 'KB-контекст заявок'],
        ['table' => 'clarification_questions', 'label' => 'Уточняющие вопросы'],
        ['table' => 'clarification_batches', 'label' => 'Батчи уточнений'],
        ['table' => 'quotation_items', 'label' => 'Позиции КП'],
        ['table' => 'quotations', 'label' => 'Коммерческие предложения'],
        ['table' => 'outbound_quote_items', 'label' => 'Позиции исходящих КП (парсер)'],
        ['table' => 'outbound_quotes', 'label' => 'Исходящие КП (парсер)'],
        ['table' => 'ai_decisions', 'label' => 'AI-решения'],
        ['table' => 'routed_mails', 'label' => 'Аудит маршрутизации писем'],
        ['table' => 'email_attachments', 'label' => 'Вложения писем'],
        ['table' => 'email_messages', 'label' => 'Письма'],
        ['table' => 'inbound_url_fetches', 'label' => 'Inbound URL fetches'],
        ['table' => 'requests', 'label' => 'Заявки'],
        ['table' => 'mailbox_folder_states', 'label' => 'IMAP sync state'],
        ['table' => 'jobs', 'label' => 'Очередь jobs'],
        ['table' => 'failed_jobs', 'label' => 'Упавшие jobs'],
        ['table' => 'notifications', 'label' => 'Уведомления'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->line('');
        $this->line('=================================================================');
        $this->line($apply
            ? '  CLEANUP TEST DATA — РЕЖИМ ВЫПОЛНЕНИЯ (--apply)'
            : '  CLEANUP TEST DATA — DRY RUN (без --apply ничего не изменится)');
        $this->line('=================================================================');
        $this->line('');

        // 1. Подсчёт операционных данных
        $this->line('--- Что будет удалено: ---');
        $totalRows = 0;
        $counts = [];
        foreach (self::WIPE_ORDER as $spec) {
            $table = $spec['table'];
            if (! Schema::hasTable($table)) {
                $this->warn(sprintf('  %-40s  [таблицы нет, пропуск]', $spec['label']));
                continue;
            }
            $cnt = DB::table($table)->count();
            $counts[$table] = $cnt;
            $totalRows += $cnt;
            $this->line(sprintf('  %-40s  %s (table: %s)', $spec['label'], number_format($cnt), $table));
        }
        $this->line('');
        $this->info(sprintf('  ИТОГО строк к удалению: %s', number_format($totalRows)));
        $this->line('');

        // 2. Определяем директоров (хранителей) и удаляемых пользователей
        $directorIds = $this->resolveDirectorIds();

        $allUsers = User::query()->get(['id', 'name', 'email']);
        $usersToKeep = $allUsers->whereIn('id', $directorIds);
        $usersToDelete = $allUsers->whereNotIn('id', $directorIds);

        $this->line('--- Пользователи: ---');
        $this->line(sprintf('  Сохраняем (роль director): %d', $usersToKeep->count()));
        foreach ($usersToKeep as $u) {
            $this->line(sprintf('    · #%d  %s  <%s>', $u->id, $u->name, $u->email));
        }
        $this->line(sprintf('  УДАЛЯЕМ: %d', $usersToDelete->count()));
        foreach ($usersToDelete as $u) {
            $this->line(sprintf('    × #%d  %s  <%s>', $u->id, $u->name, $u->email));
        }
        $this->line('');

        // 3. Ящики
        $deleteUserIds = $usersToDelete->pluck('id')->all();

        $personalMailboxesToDelete = Mailbox::query()
            ->whereIn('owner_user_id', $deleteUserIds)
            ->get(['id', 'email', 'type']);

        $sharedMailboxes = Mailbox::query()
            ->whereNull('owner_user_id')
            ->get(['id', 'email', 'is_active']);

        $personalMailboxesKept = Mailbox::query()
            ->whereIn('owner_user_id', $directorIds)
            ->get(['id', 'email', 'is_active']);

        $this->line('--- Ящики: ---');
        $this->line(sprintf('  Личные ящики к УДАЛЕНИЮ (вместе с владельцами): %d', $personalMailboxesToDelete->count()));
        foreach ($personalMailboxesToDelete as $m) {
            $this->line(sprintf('    × #%d  %s  (%s)', $m->id, $m->email, $m->type->value ?? $m->type));
        }
        $this->line(sprintf('  Личные ящики директоров (СОХРАНЯЕМ): %d', $personalMailboxesKept->count()));
        foreach ($personalMailboxesKept as $m) {
            $this->line(sprintf('    · #%d  %s  (is_active=%s)', $m->id, $m->email, $m->is_active ? 'true' : 'false'));
        }
        $this->line(sprintf('  Shared ящики (НЕ удаляем, ДЕАКТИВИРУЕМ): %d', $sharedMailboxes->count()));
        foreach ($sharedMailboxes as $m) {
            $this->line(sprintf('    · #%d  %s  (is_active=%s → false)', $m->id, $m->email, $m->is_active ? 'true' : 'false'));
        }
        $this->line('');

        // 4. Защита: должен быть хотя бы один директор
        if ($usersToKeep->isEmpty()) {
            $this->error('STOP: не найдено ни одного пользователя с ролью `director`.');
            $this->error('  Иначе после очистки в системе не останется админов.');
            $this->error('  Создайте директора через админку и запустите команду заново.');
            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('DRY RUN — реальное удаление не выполнено.');
            $this->line('Для выполнения:');
            $this->line('  php artisan system:cleanup-test-data --apply');
            return self::SUCCESS;
        }

        // 5. Двойное подтверждение
        if (! $this->confirm('Вы уверены? Это операция БЕЗ ВОЗВРАТА.', false)) {
            $this->info('Отменено.');
            return self::SUCCESS;
        }
        if (! $this->confirm(sprintf(
            'Действительно удалить %s строк, %d пользователей, %d личных ящиков?',
            number_format($totalRows),
            $usersToDelete->count(),
            $personalMailboxesToDelete->count(),
        ), false)) {
            $this->info('Отменено.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('--- Выполнение: ---');

        DB::transaction(function () use (
            $counts,
            $deleteUserIds,
            $personalMailboxesToDelete,
            $sharedMailboxes,
            $usersToDelete,
        ): void {
            // Сначала удаляем операционные таблицы
            foreach (self::WIPE_ORDER as $spec) {
                $table = $spec['table'];
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $before = $counts[$table] ?? null;
                if ($before === 0) {
                    $this->line(sprintf('  %-40s  пусто, пропуск', $spec['label']));
                    continue;
                }
                $deleted = DB::table($table)->delete();
                $this->info(sprintf('  ✓ %-40s  удалено %s', $spec['label'], number_format($deleted)));
            }

            // Деактивация shared-ящиков
            if ($sharedMailboxes->isNotEmpty()) {
                $deactivated = Mailbox::query()
                    ->whereIn('id', $sharedMailboxes->pluck('id'))
                    ->update([
                        'is_active' => false,
                        'last_synced_at' => null,
                        'last_error_at' => null,
                        'last_error_message' => null,
                    ]);
                $this->info(sprintf('  ✓ %-40s  деактивировано %d', 'Shared ящики', $deactivated));
            }

            // Удаление личных ящиков удаляемых юзеров
            if ($personalMailboxesToDelete->isNotEmpty()) {
                $delMb = Mailbox::query()
                    ->whereIn('id', $personalMailboxesToDelete->pluck('id'))
                    ->delete();
                $this->info(sprintf('  ✓ %-40s  удалено %d', 'Личные ящики (тестовые юзеры)', $delMb));
            }

            // Spatie: удаляем role assignments удаляемых юзеров
            if (! empty($deleteUserIds) && Schema::hasTable('model_has_roles')) {
                $delRoles = DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->whereIn('model_id', $deleteUserIds)
                    ->delete();
                $this->info(sprintf('  ✓ %-40s  удалено %d', 'Spatie: model_has_roles', $delRoles));
            }
            if (! empty($deleteUserIds) && Schema::hasTable('model_has_permissions')) {
                $delPerms = DB::table('model_has_permissions')
                    ->where('model_type', User::class)
                    ->whereIn('model_id', $deleteUserIds)
                    ->delete();
                if ($delPerms > 0) {
                    $this->info(sprintf('  ✓ %-40s  удалено %d', 'Spatie: model_has_permissions', $delPerms));
                }
            }

            // Удаление самих пользователей
            if ($usersToDelete->isNotEmpty()) {
                $delU = User::query()
                    ->whereIn('id', $usersToDelete->pluck('id'))
                    ->delete();
                $this->info(sprintf('  ✓ %-40s  удалено %d', 'Пользователи (тестовые)', $delU));
            }
        });

        $this->line('');
        $this->info('Готово. БД зачищена. Дальше:');
        $this->line('  1. В админке /dashboard/managers создать реальных менеджеров');
        $this->line('  2. Привязать им личные ящики через OAuth');
        $this->line('  3. Активировать shared-ящик (mail@myzip.ru) — установить is_active=true');
        $this->line('     В БД: UPDATE mailboxes SET is_active=true WHERE id=...;');
        $this->line('     Или через UI на странице ящика.');
        $this->line('  4. Перезапустить воркеры: sudo supervisorctl restart all');
        $this->line('  5. Следить за логом: tail -f storage/logs/laravel.log');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Возвращает ID всех пользователей с ролью `director` (Spatie).
     *
     * @return array<int, int>
     */
    private function resolveDirectorIds(): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'director')
            ->where('model_has_roles.model_type', User::class)
            ->pluck('model_has_roles.model_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }
}
