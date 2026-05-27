<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Models\Request as RequestModel;
use App\Services\Mail\MailDeliverToManagerService;
use Illuminate\Console\Command;

/**
 * Audit + backfill: для всех active Request с привязанным email_message и
 * assigned_user_id проверяет, что письмо доставлено в личный IMAP-ящик
 * менеджера (artifact `detected_artifacts.inbox_deliveries[user_id]`).
 * Если нет — вызывает `MailDeliverToManagerService::deliver()` напрямую.
 *
 * Кейс: jobs `DeliverToManagerInboxJob` иногда теряются (queue:restart
 * посреди в-флайт-задачи, worker crash, edge cases manual processIfRequest
 * пропускающий auto-assign chain). Менеджеры жаловались что часть заявок
 * не появляются в их Yandex 360 inbox.
 *
 * Usage:
 *   php artisan mail:backfill-manager-deliveries --dry-run
 *   php artisan mail:backfill-manager-deliveries --apply
 *   php artisan mail:backfill-manager-deliveries --apply --manager=10
 */
class MailBackfillManagerDeliveriesCommand extends Command
{
    protected $signature = 'mail:backfill-manager-deliveries
        {--dry-run : Показать что будет доставлено без записи (default)}
        {--apply : Реально вызвать deliver()}
        {--manager= : Фильтр по конкретному user_id (assignee)}';

    protected $description = 'Backfill missed cross-mailbox deliveries для активных Request (audit IMAP APPEND в личные ящики менеджеров).';

    public function handle(MailDeliverToManagerService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $managerFilter = $this->option('manager') ? (int) $this->option('manager') : null;

        // Активные статусы — для closed/paused делать backfill бессмысленно.
        $activeStatuses = ['new', 'assigned', 'in_progress', 'awaiting_client_clarification',
            'quoted', 'under_review', 'awaiting_invoice', 'invoiced', 'paid'];

        $query = RequestModel::query()
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('email_message_id')
            ->whereIn('status', $activeStatuses)
            // НЕ доставляем archived-юзерам — их mailbox мог уже быть
            // деактивирован UserObserver'ом, и даже если нет, APPEND
            // в orphan-ящик создаёт «дубли» в неиспользуемом inbox'е.
            // См. MEMORY 2026-05-27.
            ->whereHas('assignedUser', fn ($q) => $q->whereNull('archived_at'));
        if ($managerFilter !== null) {
            $query->where('assigned_user_id', $managerFilter);
        }
        $reqs = $query->with(['emailMessage', 'assignedUser'])->get();

        $stats = ['ok' => 0, 'skip_already' => 0, 'skip_no_personal' => 0,
            'skip_same_mailbox' => 0, 'fail' => 0, 'dry' => 0];

        foreach ($reqs as $r) {
            $em = $r->emailMessage;
            $u = $r->assignedUser;
            if (! $em || ! $u) {
                continue;
            }

            $managerMb = Mailbox::whereRaw('LOWER(email) = ?', [strtolower($u->email)])->first();
            if (! $managerMb) {
                $stats['skip_no_personal']++;
                continue;
            }

            if ((int) $em->mailbox_id === (int) $managerMb->id) {
                // Письмо уже в личном ящике менеджера — нечего доставлять.
                $stats['skip_same_mailbox']++;
                continue;
            }

            $artifacts = (array) ($em->detected_artifacts ?? []);
            $deliveries = (array) ($artifacts['inbox_deliveries'] ?? []);
            $alreadyDelivered = false;
            foreach ($deliveries as $d) {
                if ((int) ($d['user_id'] ?? 0) === $u->id) {
                    $alreadyDelivered = true;
                    break;
                }
            }
            if ($alreadyDelivered) {
                $stats['skip_already']++;
                continue;
            }

            if (! $apply) {
                $this->line(sprintf('  DRY: msg#%d %s → %s', $em->id, $r->internal_code, $u->name));
                $stats['dry']++;
                continue;
            }

            try {
                $result = $svc->deliver($em, $u);
                if ($result) {
                    $this->line(sprintf('  OK : msg#%d %s → %s', $em->id, $r->internal_code, $u->name));
                    $stats['ok']++;
                } else {
                    $this->warn(sprintf('  FAIL: msg#%d %s → %s (silent skip)', $em->id, $r->internal_code, $u->name));
                    $stats['fail']++;
                }
            } catch (\Throwable $e) {
                $this->error(sprintf('  ERR : msg#%d %s — %s', $em->id, $r->internal_code, $e->getMessage()));
                $stats['fail']++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово: доставлено=%d · уже_было=%d · нет_личного_ящика=%d · уже_в_ящике=%d · ошибки=%d · dry=%d',
            $stats['ok'], $stats['skip_already'], $stats['skip_no_personal'],
            $stats['skip_same_mailbox'], $stats['fail'], $stats['dry']
        ));
        if (! $apply && $stats['dry'] > 0) {
            $this->warn('--dry-run: deliver() не вызван. Используй --apply.');
        }

        return self::SUCCESS;
    }
}
