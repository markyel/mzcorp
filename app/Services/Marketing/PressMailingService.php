<?php

namespace App\Services\Marketing;

use App\Jobs\Marketing\SendPressReleaseLetterJob;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\PressOutlet;
use App\Models\PressRelease;
use App\Models\PressReleaseSend;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Рассылка пресс-релиза по редакциям.
 *
 * Каждой редакции — своё письмо из обычного ящика через тот же путь, что и
 * письма менеджеров (черновик → OutgoingMailSender): подпись автора, копия в
 * «Отправленных», автоповтор при сбое SMTP. Письма уходят по одному с паузой
 * (services.marketing.press.send_interval_seconds), а не одной пачкой в
 * скрытой копии — так их не режут спам-фильтры и у каждого свой Message-ID,
 * по которому ответ редакции находит релиз (PressReplyHandler).
 */
class PressMailingService
{
    public function __construct(
        private readonly EmailDraftService $drafts,
        private readonly OutgoingMailSender $sender,
    ) {
    }

    /** Ящики, с которых можно рассылать. @return Collection<int, Mailbox> */
    public function senderMailboxes(): Collection
    {
        return Mailbox::query()->where('is_active', true)->orderBy('email')->get()
            ->filter(fn (Mailbox $m) => $m->canSendOutbound())
            ->values();
    }

    public function defaultMailbox(): ?Mailbox
    {
        $email = mb_strtolower((string) config('services.marketing.press.mailbox_email'));

        return $this->senderMailboxes()->first(fn (Mailbox $m) => mb_strtolower((string) $m->email) === $email)
            ?? $this->senderMailboxes()->first();
    }

    /**
     * Адресаты рассылки: активные площадки с адресом по приоритетам и категориям.
     *
     * @param  list<string>  $priorities
     * @param  list<string>  $categories  пусто — все категории
     * @return Collection<int, PressOutlet>
     */
    public function audience(array $priorities, array $categories = []): Collection
    {
        return PressOutlet::query()->active()
            ->whereIn('priority', $priorities)
            ->when($categories !== [], fn ($q) => $q->whereIn('category', $categories))
            ->orderBy('priority')->orderBy('category')->orderBy('name')
            ->get()
            ->filter(fn (PressOutlet $o) => $o->primaryEmail() !== null)
            ->values();
    }

    /**
     * Поставить рассылку в очередь. Редакции, которым этот релиз уже ушёл,
     * пропускаются — повторный запуск дошлёт только новым.
     *
     * @param  Collection<int, PressOutlet>  $outlets
     * @return array{ok: bool, message: string, queued: int}
     */
    public function queue(PressRelease $release, Collection $outlets, Mailbox $mailbox, User $author): array
    {
        if (! $release->isSendable()) {
            return ['ok' => false, 'message' => 'У релиза нет темы письма или текста.', 'queued' => 0];
        }
        if (! $mailbox->canSendOutbound()) {
            return ['ok' => false, 'message' => 'С ящика '.$mailbox->email.' сейчас нельзя отправить письмо.', 'queued' => 0];
        }

        $already = $release->sends()->pluck('press_outlet_id')->all();
        $interval = max(0, (int) config('services.marketing.press.send_interval_seconds', 45));
        $queued = 0;

        foreach ($outlets as $outlet) {
            $email = $outlet->primaryEmail();
            if ($email === null || in_array($outlet->id, $already, true)) {
                continue;
            }

            $send = PressReleaseSend::create([
                'press_release_id' => $release->id,
                'press_outlet_id' => $outlet->id,
                'email' => $email,
                'status' => 'queued',
            ]);
            SendPressReleaseLetterJob::dispatch($send->id, $mailbox->id, $author->id)
                ->delay(now()->addSeconds($queued * $interval));
            $queued++;
        }

        if ($queued === 0) {
            return ['ok' => false, 'message' => 'Новых адресатов нет: всем выбранным редакциям релиз уже ушёл.', 'queued' => 0];
        }

        $release->forceFill([
            'status' => 'sent',
            'sent_at' => $release->sent_at ?? now(),
            'mailbox_id' => $mailbox->id,
        ])->save();

        $minutes = (int) ceil(($queued - 1) * $interval / 60);

        return [
            'ok' => true,
            'message' => 'В очереди писем: '.$queued.'. Уходят по одному'.($minutes > 0 ? ', примерно за '.$minutes.' мин' : '').'.',
            'queued' => $queued,
        ];
    }

    /** Отправить одно письмо рассылки (из очереди). */
    public function sendOne(PressReleaseSend $send, Mailbox $mailbox, User $author): void
    {
        $send->loadMissing(['release', 'outlet']);
        $release = $send->release;
        if ($release === null || $send->status !== 'queued') {
            return;
        }

        $draft = $this->drafts->createComposeFree($mailbox, $author);
        $this->drafts->update($draft, [
            'subject' => (string) $release->subject,
            'to_recipients' => [['email' => $send->email, 'name' => (string) $send->outlet?->name]],
            'body_plain' => $this->letter($release),
        ]);
        // Рекламный блок под подписью — для писем клиентам, не редакциям:
        // «без блока» фиксируется заранее, как это делает сам MarketingBlockService.
        $draft->forceFill(['detected_artifacts' => [MarketingBlockService::ARTIFACT_BLOCK_ID => null]])->save();

        try {
            $res = $this->sender->sendDraft($draft->id, queueOnTransientFailure: true);
        } catch (\Throwable $e) {
            $res = ['success' => false, 'error' => 'exception', 'error_message' => $e->getMessage()];
        }

        if ($res['success'] || ($res['queued'] ?? false)) {
            // Встало в очередь повторов SMTP — письмо уйдёт само, ответ найдёт
            // релиз по email_message_id: Message-ID допишется при отправке.
            $fresh = EmailMessage::find($draft->id);
            $send->forceFill([
                'status' => 'sent',
                'email_message_id' => $draft->id,
                'message_id' => $res['success'] ? $fresh?->message_id : null,
                'sent_at' => $res['success'] ? now() : null,
                'error' => $res['success'] ? null : 'SMTP недоступен, письмо в очереди повторов.',
            ])->save();

            return;
        }

        Log::warning('PressMailingService: письмо не ушло', [
            'press_release_send_id' => $send->id,
            'email' => $send->email,
            'error' => $res['error'] ?? null,
            'message' => $res['error_message'] ?? null,
        ]);
        $send->forceFill([
            'status' => 'failed',
            'error' => mb_substr((string) ($res['error_message'] ?? $res['error'] ?? 'ошибка отправки'), 0, 1000),
        ])->save();

        // Неотправленный черновик не должен висеть в «Почте» автора.
        try {
            $draft = EmailMessage::find($draft->id);
            if ($draft?->is_draft) {
                $this->drafts->delete($draft);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Пробное письмо на свой адрес — посмотреть, как релиз выглядит у
     * редакции (подпись, абзацы). В рассылку не записывается.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTest(PressRelease $release, Mailbox $mailbox, User $author, string $email): array
    {
        if (! $release->isSendable()) {
            return ['ok' => false, 'message' => 'У релиза нет темы письма или текста.'];
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Неверный адрес для пробы.'];
        }

        $draft = $this->drafts->createComposeFree($mailbox, $author);
        $this->drafts->update($draft, [
            'subject' => '[проба] '.$release->subject,
            'to_recipients' => [['email' => $email, 'name' => '']],
            'body_plain' => $this->letter($release),
        ]);
        $draft->forceFill(['detected_artifacts' => [MarketingBlockService::ARTIFACT_BLOCK_ID => null]])->save();

        $res = $this->sender->sendDraft($draft->id);
        if ($res['success']) {
            return ['ok' => true, 'message' => 'Пробное письмо ушло на '.$email.'.'];
        }

        $draft = EmailMessage::find($draft->id);
        if ($draft?->is_draft) {
            $this->drafts->delete($draft);
        }

        return ['ok' => false, 'message' => 'Не ушло: '.($res['error_message'] ?? $res['error'] ?? 'ошибка отправки')];
    }

    /** Повторить неушедшие письма релиза. */
    public function retryFailed(PressRelease $release, Mailbox $mailbox, User $author): int
    {
        $interval = max(0, (int) config('services.marketing.press.send_interval_seconds', 45));
        $n = 0;
        foreach ($release->sends()->where('status', 'failed')->get() as $send) {
            $send->forceFill(['status' => 'queued', 'error' => null])->save();
            SendPressReleaseLetterJob::dispatch($send->id, $mailbox->id, $author->id)
                ->delay(now()->addSeconds($n * $interval));
            $n++;
        }

        return $n;
    }

    /** Текст письма: сопроводительный абзац и релиз под ним. */
    public function letter(PressRelease $release): string
    {
        $cover = trim((string) $release->cover_note);

        return ($cover !== '' ? $cover."\n\n" : '').trim((string) $release->body);
    }

    /**
     * Письмо — ответ на нашу рассылку? Матч по In-Reply-To / References с
     * Message-ID отправленного письма.
     */
    public function matchReply(EmailMessage $message): ?PressReleaseSend
    {
        $ids = array_values(array_filter(array_unique(array_merge(
            [(string) $message->in_reply_to],
            array_map('strval', (array) ($message->references_header ?? [])),
        ))));
        if ($ids === []) {
            return null;
        }

        return PressReleaseSend::query()
            ->whereIn('email_message_id', EmailMessage::query()->select('id')->whereIn('message_id', $ids))
            ->latest('id')
            ->first();
    }

    public function attachReply(PressReleaseSend $send, EmailMessage $message): void
    {
        $send->forceFill([
            'status' => 'replied',
            'replied_at' => $send->replied_at ?? ($message->sent_at ?? now()),
            'reply_email_message_id' => $send->reply_email_message_id ?? $message->id,
        ])->save();
    }
}
