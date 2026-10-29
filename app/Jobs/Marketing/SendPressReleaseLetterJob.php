<?php

namespace App\Jobs\Marketing;

use App\Models\Mailbox;
use App\Models\PressReleaseSend;
use App\Models\User;
use App\Services\Marketing\PressMailingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Одно письмо рассылки пресс-релиза. Повторы при сбое SMTP — у самой
 * отправки (очередь повторов OutgoingSendRetryService), поэтому попытка одна:
 * второй прогон задачи отправил бы редакции то же письмо дважды.
 */
class SendPressReleaseLetterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $sendId,
        public readonly int $mailboxId,
        public readonly int $authorId,
    ) {
    }

    public function handle(PressMailingService $mailing): void
    {
        $lock = Cache::lock('press:send:'.$this->sendId, $this->timeout + 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $send = PressReleaseSend::find($this->sendId);
            $mailbox = Mailbox::find($this->mailboxId);
            $author = User::find($this->authorId);
            if ($send === null || $mailbox === null || $author === null) {
                $send?->forceFill(['status' => 'failed', 'error' => 'Нет ящика или автора рассылки.'])->save();

                return;
            }

            $mailing->sendOne($send, $mailbox, $author);
        } finally {
            $lock->release();
        }
    }
}
