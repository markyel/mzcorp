<?php

namespace App\Services\Mail;

use App\Enums\ClientNotificationType;
use App\Enums\ClosedLostReason;
use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Models\ClientNotificationSent;
use App\Models\ClientNotificationTemplate;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Автоматические уведомления клиенту (Phase 6).
 *
 * Каждый тип:
 *  - имеет редактируемый шаблон в client_notification_templates;
 *  - может быть выключен через is_enabled;
 *  - отправляется как **reply в тред заявки** (In-Reply-To + References);
 *  - идемпотентен через uniq(request_id, type, scope_key).
 *
 * From-ящик определяется через OutgoingMailboxResolver — тот же, что
 * используется для ручных reply'ев менеджера. Обычно это ящик в который
 * пришло первое письмо клиента, либо личный ящик assigned-менеджера.
 *
 * Текст рендерится:
 *   1. Markdown body_template + placeholders → MD string.
 *   2. League CommonMark → HTML (без внешней обёртки).
 *   3. Plain-text вариант — голый rendered MD.
 *
 * Подпись (шаблонная, EmailSignatureService), футер с № заявки и цитата
 * исходного письма доклеиваются единообразно в OutgoingMailMimeBuilder::
 * composeFinalBody() при отправке — ровно как для ручных писем менеджера,
 * поэтому уведомление выглядит как обычный ответ в треде.
 */
class ClientNotificationService
{
    public function __construct(
        private readonly EmailDraftService $drafts,
        private readonly OutgoingMailSender $sender,
        private readonly ClientNotificationOptoutService $optouts,
    ) {
    }

    /**
     * Отправить уведомление типа OrderReceived. Триггерится из
     * AssignmentService::autoAssign для НОВЫХ заявок.
     *
     * Условия (проверяются вызывающим):
     *  - Request не имеет inheritance_parent_id (не наследник);
     *  - origin EmailMessage.in_reply_to IS NULL (не reply на чужой тред).
     */
    public function sendOrderReceived(Request $request): bool
    {
        $origin = $request->emailMessage;
        if (! $origin) {
            return false;
        }

        return $this->dispatch(
            request: $request,
            type: ClientNotificationType::OrderReceived,
            scopeKey: '',
            replyTo: $origin,
            extra: [
                'items_count' => $request->items()->where('is_active', true)->count(),
                'items_summary' => $this->buildItemsSummary($request),
            ],
        );
    }

    /**
     * «Заявка закрыта». Триггерится синхронным hook'ом в RequestStateService::transitionTo
     * после успешного перехода в ClosedLost.
     *
     * Guard: НЕ слать если закрытие пришло из outbound-сигнала менеджера
     * (state_change.payload.detector_type === 'outbound_declined'). В этом
     * случае менеджер уже сам написал клиенту ответ-отказ — повторное
     * уведомление избыточно. Шлём для:
     *  - manual UI (CloseLostDialog) — payload.detector_type отсутствует;
     *  - inbound_decline — клиент сам отказался, подтверждаем что услышали;
     *  - системного закрытия (auto-recover unassigned) — клиент в курсе быть должен.
     */
    public function sendOrderClosedLost(Request $request, RequestStateChange $stateChange): bool
    {
        $payload = is_array($stateChange->payload) ? $stateChange->payload : [];
        $detectorType = (string) ($payload['detector_type'] ?? '');

        // Outbound — менеджер уже написал клиенту, не слать ещё одно.
        if ($detectorType === 'outbound_declined') {
            return false;
        }

        // Найдём anchor для треда — последнее inbound от клиента в этой Request,
        // либо origin email_message заявки.
        $replyTo = EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->where('direction', 'inbound')
            ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
            ->orderByDesc('id')
            ->first()
            ?? $request->emailMessage;

        if (! $replyTo) {
            return false;
        }

        $reasonEnum = ClosedLostReason::tryFrom((string) $request->closed_lost_reason);
        $reasonLabel = $reasonEnum?->label() ?? ($request->closed_lost_reason ?: 'не указана');
        $comment = trim((string) ($request->closed_lost_comment ?? ''));

        return $this->dispatch(
            request: $request,
            type: ClientNotificationType::OrderClosedLost,
            scopeKey: '', // одна заявка — одно закрытие; idempotent на повторных вызовах
            replyTo: $replyTo,
            extra: [
                'close_reason_label' => $reasonLabel,
                'close_comment' => $comment !== '' ? 'Комментарий: ' . $comment : '',
            ],
        );
    }

    /**
     * Универсальная точка отправки — используется hooks и cron.
     *
     * @param  array<string, mixed>  $extra placeholder-данные, специфичные для типа
     */
    public function dispatch(
        Request $request,
        ClientNotificationType $type,
        string $scopeKey,
        EmailMessage $replyTo,
        array $extra = [],
        ?User $triggeredBy = null,
    ): bool {
        $template = ClientNotificationTemplate::forType($type);

        if (! $template->is_enabled) {
            return false;
        }

        if (! $request->client_email) {
            Log::info('ClientNotificationService: skip — no client email', [
                'request_id' => $request->id,
                'type' => $type->value,
            ]);

            return false;
        }

        // Стоп-лист авто-уведомлений: клиент попросил не слать (этот тип).
        if ($this->optouts->isSuppressed($request->client_email, $type)) {
            Log::info('ClientNotificationService: skip — client opted out', [
                'request_id' => $request->id,
                'type' => $type->value,
                'client_email' => $request->client_email,
            ]);

            return false;
        }

        // Идемпотентность: один и тот же type+scope_key уже отправляли по этой заявке?
        $existed = ClientNotificationSent::where('request_id', $request->id)
            ->where('type', $type->value)
            ->where('scope_key', $scopeKey)
            ->exists();
        if ($existed) {
            return false;
        }

        $placeholders = $this->buildPlaceholders($request, $type, $extra);

        $subject = $this->renderPlaceholders($template->subject_template, $placeholders);
        $bodyMd = $this->renderPlaceholders($template->body_template, $placeholders);

        // Markdown → HTML. БЕЗ внешней «карточки»-обёртки: уведомление уходит
        // как обычный ответ в треде. Общую шаблонную подпись, футер с № заявки
        // и цитату исходного письма доклеит OutgoingMailMimeBuilder::
        // composeFinalBody() при отправке — ту же, что у ручных писем менеджера.
        $bodyHtml = (new GithubFlavoredMarkdownConverter())->convert($bodyMd)->getContent();

        // Plain-text вариант — Markdown как есть.
        $bodyPlain = $bodyMd;

        // Создаём draft как reply в тред заявки — берём существующую логику
        // EmailDraftService::createReply, OutgoingMailboxResolver внутри
        // выбирает правильный From-ящик (origin/sticky/assigned).
        $draft = $this->drafts->createReply(
            request: $request,
            replyTo: $replyTo,
            author: $triggeredBy ?? $request->assignedUser ?? User::query()->whereNotNull('email')->first(),
            replyAll: false,
        );

        // Threading: Gmail/Yandex склеивают переписку в один тред не только по
        // In-Reply-To/References, но и по совпадению нормализованной темы. Если
        // подставить сюда subject_template («Ваш запрос … принят в работу»), он
        // не совпадёт с темой клиентского треда и письмо уйдёт ОТДЕЛЬНЫМ тредом.
        // createReply() уже выставил draft.subject = «Re: <тема клиента>» — его
        // и оставляем для треда. subject_template используем как fallback
        // (пустая тема у anchor'а — edge-case) и как заголовок HTML-обёртки.
        $threadSubject = trim((string) $draft->subject);
        if ($threadSubject === '' || rtrim(mb_strtolower($threadSubject), ': ') === 're') {
            $threadSubject = $subject;
        }

        // Body — наш HTML + Plain, в textarea не показываем (это автомат).
        //
        // Получатель — ВСЕГДА client_email заявки, переопределяем явно. Это
        // расцепляет адресата и тред-якорь: якорем может быть наше ИСХОДЯЩЕЕ
        // письмо с КП/счётом (чтобы напоминание село в ветку документа), а у
        // исходящего from_email = наш ящик — computeRecipients вырезал бы его
        // как «свой» и To оказался бы пустым. Для уведомления адресат
        // однозначен — клиент, поэтому ставим его напрямую.
        $draft->forceFill([
            'subject' => $threadSubject,
            'body_html' => $bodyHtml,
            'body_plain' => $bodyPlain,
            'to_recipients' => [[
                'email' => $request->client_email,
                'name' => (string) ($request->client_name ?? ''),
            ]],
        ])->save();

        $result = $this->sender->sendDraft($draft->id);
        if (! ($result['success'] ?? false)) {
            Log::warning('ClientNotificationService: send failed', [
                'request_id' => $request->id,
                'type' => $type->value,
                'error' => $result['error'] ?? 'unknown',
            ]);
            // Драфт автоматный (менеджер его не видел и не писал) — при сбое
            // удаляем, следующий прогон создаст новый. Иначе ретраи копят
            // мусор: revival_offer по заявке 5426 падал 11 дней каждый час
            // (битые References от mail.ru) → 257 драфтов-дублей в треде.
            try {
                $draft->delete();
            } catch (\Throwable $e) {
                Log::warning('ClientNotificationService: failed-draft cleanup failed', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }

        $sentDraft = $result['draft']->refresh();

        ClientNotificationSent::create([
            'request_id' => $request->id,
            'type' => $type->value,
            'scope_key' => $scopeKey,
            'outgoing_email_message_id' => $sentDraft->id,
            'reply_to_email_message_id' => $replyTo->id,
            'recipient_email' => $request->client_email,
            'subject' => $threadSubject,
            'body_rendered_html' => $bodyHtml,
            'body_rendered_plain' => $bodyPlain,
            'sent_at' => now(),
            'triggered_by_user_id' => $triggeredBy?->id,
        ]);

        Log::info('ClientNotificationService: sent', [
            'request_id' => $request->id,
            'type' => $type->value,
            'scope_key' => $scopeKey,
            'recipient' => $request->client_email,
        ]);

        return true;
    }

    /**
     * Превью без отправки — для UI Admin/Notifications.
     * Использует реальный Request как контекст. Возвращает rendered subject + html + plain.
     *
     * @return array{subject: string, body_html: string, body_plain: string}
     */
    public function preview(ClientNotificationTemplate $template, Request $request, array $extra = []): array
    {
        $type = $template->type;
        $placeholders = $this->buildPlaceholders($request, $type, $extra);

        $subject = $this->renderPlaceholders($template->subject_template, $placeholders);
        $bodyMd = $this->renderPlaceholders($template->body_template, $placeholders);
        $bodyHtml = (new GithubFlavoredMarkdownConverter())->convert($bodyMd)->getContent();

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_plain' => $bodyMd,
        ];
    }

    /**
     * Сформировать массив `{{ var }} => value`.
     *
     * Общие placeholders + type-specific из $extra. Если в extra нет какого-то
     * type-specific placeholder'а — оставляем плейсхолдер как есть (`{{ foo }}`),
     * чтобы было видно что данных не хватает.
     *
     * @return array<string, string>
     */
    private function buildPlaceholders(Request $request, ClientNotificationType $type, array $extra): array
    {
        $manager = $request->assignedUser;

        $common = [
            'request_code' => (string) $request->internal_code,
            'manager_name' => (string) ($manager?->name ?? 'отдел продаж'),
            'manager_email' => (string) ($manager?->email ?? config('mail.from.address', 'info@myzip.ru')),
            'manager_phone' => (string) ($manager?->phone ?? ''),
            'client_name' => (string) ($request->client_name ?: $this->guessClientNameFromEmail($request->client_email)),
            'company_name' => 'MyZip',
            // Conditional: для OrderReceived (и любого другого где это
            // полезно) — заполняем «Ответственный менеджер: ...» ТОЛЬКО
            // если письмо пришло на общий ящик. Если на личный — клиент
            // и так знает, к кому пишет; повторять имя в авто-уведомлении
            // выглядит формально.
            'manager_intro' => $this->buildManagerIntro($request, $manager),
        ];

        $extraStr = [];
        foreach ($extra as $k => $v) {
            $extraStr[$k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return array_merge($common, $extraStr);
    }

    /**
     * Conditional блок про ответственного менеджера. Если оригинальное
     * письмо пришло на shared mailbox (info@/mail@) — клиент не знает,
     * кто его обрабатывает, явно представляем менеджера. Если письмо
     * пришло на personal — пустая строка (Markdown схлопнет пустой абзац).
     */
    private function buildManagerIntro(Request $request, ?User $manager): string
    {
        if (! $manager) {
            return '';
        }
        $origin = $request->emailMessage;
        $mailboxType = $origin?->mailbox?->type;
        if ($mailboxType !== MailboxType::Shared) {
            return '';
        }

        $email = $manager->email ? ' (' . $manager->email . ')' : '';

        return 'Ответственный менеджер: **' . $manager->name . '**' . $email . '.';
    }

    /**
     * `Hello {{ var }} world` → `Hello value world`. Поддерживает пробелы
     * вокруг placeholder'а: `{{var}}`, `{{ var }}`, `{{  var  }}`.
     */
    private function renderPlaceholders(string $template, array $placeholders): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/i',
            function ($m) use ($placeholders) {
                $key = $m[1];

                return array_key_exists($key, $placeholders)
                    ? $placeholders[$key]
                    : $m[0]; // оставляем как есть если данных нет
            },
            $template
        );
    }

    private function buildItemsSummary(Request $request): string
    {
        $items = $request->items()->where('is_active', true)->orderBy('position')->limit(5)->get();
        if ($items->isEmpty()) {
            return '—';
        }

        $lines = [];
        foreach ($items as $i) {
            $name = mb_substr((string) $i->parsed_name, 0, 80);
            $art = $i->parsed_article ? ' (' . $i->parsed_article . ')' : '';
            $qty = $i->parsed_qty ? ' — ' . (int) $i->parsed_qty . ' шт' : '';
            $lines[] = '· ' . $name . $art . $qty;
        }
        if ($request->items()->where('is_active', true)->count() > 5) {
            $lines[] = '… и ещё';
        }

        return implode("\n", $lines);
    }

    /**
     * Если client_name не заполнен — попробуем достать username из email
     * (`ivanov@example.com` → «ivanov»). Лучше чем «уважаемый клиент».
     */
    private function guessClientNameFromEmail(?string $email): string
    {
        if (! $email) {
            return 'уважаемый клиент';
        }
        $local = explode('@', $email, 2)[0] ?? '';
        $local = trim($local);
        if ($local === '') {
            return 'уважаемый клиент';
        }

        return Str::title(str_replace(['.', '_', '-'], ' ', $local));
    }
}
