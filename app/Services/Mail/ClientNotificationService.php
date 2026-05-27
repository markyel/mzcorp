<?php

namespace App\Services\Mail;

use App\Enums\ClientNotificationType;
use App\Enums\MailDirection;
use App\Models\ClientNotificationSent;
use App\Models\ClientNotificationTemplate;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
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
 *   2. League CommonMark → HTML.
 *   3. Wrap в общий MyZip-шаблон (resources/views/emails/notification_wrap).
 *   4. Plain-text fallback — голый rendered MD без HTML-обёртки.
 */
class ClientNotificationService
{
    public function __construct(
        private readonly EmailDraftService $drafts,
        private readonly OutgoingMailSender $sender,
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

        // Markdown → HTML → wrap.
        $bodyHtmlInner = (new GithubFlavoredMarkdownConverter())->convert($bodyMd)->getContent();
        $bodyHtml = View::make('emails.notification_wrap', [
            'subject' => $subject,
            'bodyHtml' => $bodyHtmlInner,
        ])->render();

        // Plain-text fallback — Markdown как есть (без HTML-обёртки).
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

        // Перезаписываем subject (createReply ставит Re:..., нам нужен наш).
        // Body — наш HTML + Plain, в textarea не показываем (это автомат).
        $draft->forceFill([
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_plain' => $bodyPlain,
        ])->save();

        $result = $this->sender->sendDraft($draft->id);
        if (! ($result['success'] ?? false)) {
            Log::warning('ClientNotificationService: send failed', [
                'request_id' => $request->id,
                'type' => $type->value,
                'error' => $result['error'] ?? 'unknown',
            ]);

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
            'subject' => $subject,
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
        $bodyHtmlInner = (new GithubFlavoredMarkdownConverter())->convert($bodyMd)->getContent();
        $bodyHtml = View::make('emails.notification_wrap', [
            'subject' => $subject,
            'bodyHtml' => $bodyHtmlInner,
        ])->render();

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
        ];

        $extraStr = [];
        foreach ($extra as $k => $v) {
            $extraStr[$k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return array_merge($common, $extraStr);
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
