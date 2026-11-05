<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Log;

/**
 * Режим наблюдения (MAIL_IMAP_WRITES=false, перезапуск после аварии 05.11.2026): система читает
 * почту и принимает решения как обычно, но в сами ящики ничего не пишет — сотрудники работают
 * в своих почтовых программах, и перемещение писем в папки менеджеров, копии в личные ящики,
 * архив при переназначении, метки, копии в «Отправленные» и отметки «прочитано» им бы мешали.
 * Каждое пропущенное действие — в лог «IMAP write skipped (observation)»: что система сделала бы.
 */
final class ImapWriteGuard
{
    public static function allowed(string $action, array $context = []): bool
    {
        if (config('services.mail.imap_writes', true)) {
            return true;
        }
        Log::info('IMAP write skipped (observation): '.$action, $context);

        return false;
    }
}
