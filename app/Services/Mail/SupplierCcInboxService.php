<?php

namespace App\Services\Mail;

use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Ящик rfq@mzcorp.ru — копии ВНЕШНЕЙ переписки с поставщиками (переговоры
 * вне системы). В теме или теле письма — номер заявки M-YYYY-NNNN, по которой
 * запрашивался поставщик.
 *
 * Входящие на этот ящик НЕ создают клиентских заявок. Пайплайн:
 *   1. вытащить номер заявки из темы/тела;
 *   2. найти Request, привязать письмо (related_request_id, category=
 *      supplier_reply → видно во вкладке «Переписка», статус не трогает);
 *   3. уведомить ответственного менеджера письмом С ЭТОГО ЖЕ ящика (rfq@) —
 *      «по заявке … новое сообщение в переписке с поставщиком» + ссылка.
 *
 * Ветка подключена в MailRouter::route() (ранний выход). Уведомление уходит с
 * заголовком X-MyLift-System-Notification — его копия в Sent/личных ящиках
 * не зациклится (гард в начале route()).
 */
class SupplierCcInboxService
{
    /** Номер заявки: M-YYYY-NNNN. */
    private const CODE_RE = '/\bM-\d{4}-\d{1,6}\b/iu';

    public function __construct(private readonly OutgoingMailSender $sender)
    {
    }

    public function inboxEmail(): string
    {
        return mb_strtolower(trim((string) config('services.supplier_cc.inbox_email', 'rfq@mzcorp.ru')));
    }

    /** Входящее письмо-копия на rfq-ящик? */
    public function isRfqInboxMessage(EmailMessage $message): bool
    {
        if ($message->direction !== MailDirection::Inbound) {
            return false;
        }
        $mbEmail = mb_strtolower(trim((string) ($message->mailbox?->email ?? '')));

        return $mbEmail !== '' && $mbEmail === $this->inboxEmail();
    }

    /**
     * Обработать копию переписки с поставщиком: найти заявку, приложить,
     * уведомить менеджера. Клиентскую заявку НЕ создаём.
     */
    public function ingest(EmailMessage $message): void
    {
        $code = $this->extractCode($message);
        $request = $code !== null
            ? Request::query()->whereRaw('LOWER(internal_code) = ?', [mb_strtolower($code)])->first()
            : null;

        if ($request === null) {
            $message->forceFill([
                'category' => EmailCategory::SupplierReply->value,
                'category_reasoning' => $code === null
                    ? 'rfq@: номер заявки не распознан в теме/теле — не привязано'
                    : "rfq@: заявка {$code} не найдена — не привязано",
                'categorized_at' => now(),
                'classified_at' => now(),
            ])->save();
            Log::warning('SupplierCcInbox: request not matched', [
                'email_message_id' => $message->id, 'code' => $code,
            ]);

            return;
        }

        $message->forceFill([
            'related_request_id' => $request->id,
            'category' => EmailCategory::SupplierReply->value,
            'category_reasoning' => "Копия переписки с поставщиком (rfq@), привязана по номеру {$request->internal_code}",
            'categorized_at' => now(),
            'classified_at' => now(),
        ])->save();

        Log::info('SupplierCcInbox: attached to request', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'code' => $request->internal_code,
        ]);

        $this->notifyManager($request, $message);
    }

    private function extractCode(EmailMessage $message): ?string
    {
        $hay = (string) $message->subject."\n".(string) $message->body_plain;
        if (preg_match(self::CODE_RE, $hay, $m) === 1) {
            return mb_strtoupper($m[0]);
        }

        return null;
    }

    /** Уведомить ответственного менеджера письмом с rfq-ящика. */
    private function notifyManager(Request $request, EmailMessage $message): void
    {
        $manager = $request->assignedUser;
        if ($manager === null || trim((string) $manager->email) === '') {
            Log::info('SupplierCcInbox: no assigned manager to notify', ['request_id' => $request->id]);

            return;
        }

        $rfq = Mailbox::query()
            ->whereRaw('LOWER(email) = ?', [$this->inboxEmail()])
            ->where('is_active', true)
            ->first();
        if ($rfq === null || ! $rfq->canSendOutbound()) {
            Log::warning('SupplierCcInbox: rfq mailbox unavailable for notify', ['request_id' => $request->id]);

            return;
        }

        $code = (string) $request->internal_code;
        $url = route('requests.show', $request->id);
        $from = trim((string) ($message->from_name ?: $message->from_email));
        $subject = "Переписка с поставщиком по заявке {$code}";
        $html = '<p style="font-size:14px;margin:0 0 12px">По заявке <b>'.e($code).'</b> появилось новое сообщение '
            .'в переписке с поставщиком'.($from !== '' ? ' (от '.e($from).')' : '').'.</p>'
            .'<p style="font-size:14px;margin:0"><a href="'.e($url).'">Открыть заявку '.e($code).'</a></p>';

        try {
            $email = (new Email())
                ->from(new Address($rfq->email, 'MyLift · RFQ'))
                ->to($manager->email)
                ->subject($subject)
                ->html($html);
            // Маркер: копия уведомления в Sent/личных ящиках не должна
            // линковаться к заявкам / плодить обработку (гард в MailRouter).
            $email->getHeaders()->addTextHeader('X-MyLift-System-Notification', '1');

            $this->sender->buildSmtpTransport($rfq)->send($email);

            Log::info('SupplierCcInbox: manager notified', [
                'request_id' => $request->id, 'manager_id' => $manager->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('SupplierCcInbox: notify send failed', [
                'request_id' => $request->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
