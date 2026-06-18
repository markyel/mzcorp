<?php

namespace App\Console\Commands\Suppliers;

use App\Services\Supplier\SupplierReminderService;
use Illuminate\Console\Command;

/**
 * Авто-напоминания поставщикам по открытым RFQ без ответа (Фаза 3.5).
 * Запускается по расписанию (см. routes/console.php). --dry-run — только показать.
 */
class RemindSuppliersCommand extends Command
{
    protected $signature = 'suppliers:remind {--dry-run : Показать кандидатов без отправки}';

    protected $description = 'Отправить напоминания поставщикам по открытым запросам расценки без ответа';

    public function handle(SupplierReminderService $service): int
    {
        if (! (bool) config('services.suppliers.reminder.enabled', true)) {
            $this->info('Напоминания поставщикам выключены (services.suppliers.reminder.enabled=false).');

            return self::SUCCESS;
        }

        $due = $service->dueInquiries();
        $this->info("Кандидатов на напоминание: {$due->count()}");

        $dry = (bool) $this->option('dry-run');
        $sent = 0;
        $failed = 0;

        foreach ($due as $inq) {
            $label = "#{$inq->id} {$inq->supplier_email} (напоминаний: {$inq->reminders_sent})";
            if ($dry) {
                $this->line("  [dry] {$label}");
                continue;
            }
            if ($service->remind($inq)) {
                $sent++;
                $this->line("  ✓ {$label}");
            } else {
                $failed++;
                $this->warn("  ✗ {$label}");
            }
        }

        if (! $dry) {
            $this->info("Отправлено: {$sent}, ошибок: {$failed}");
        }

        return self::SUCCESS;
    }
}
