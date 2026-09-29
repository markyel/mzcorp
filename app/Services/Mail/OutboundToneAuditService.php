<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\MediaProfileEntry;
use App\Models\OutboundToneReview;
use App\Models\Supplier;
use App\Models\User;
use App\Prompts\Mail\OutboundToneAuditPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Проверка писем менеджеров клиентам на соответствие образу компании.
 *
 * Отбор: исходящие письма по заявкам, отправленные менеджером, где среди
 * получателей есть внешний адрес, не поставщик. Проверяем только собственный
 * текст менеджера — без цитаты, подписи и рекламного блока — и рядом даём
 * последнее письмо клиента: «гарантии нет» в ответ на вопрос о гарантии на
 * изготовленную по размерам деталь и то же самое на ровном месте — разные вещи.
 *
 * Модель — gpt-4o-mini, пачками: писем десятки тысяч, а правило одно.
 */
class OutboundToneAuditService
{
    /** Писем в одном запросе к модели. */
    public const BATCH = 8;

    /**
     * Автоуведомления клиентам (client_notification_templates) — это наши
     * шаблоны, а не слова менеджера: проверять незачем.
     */
    private const AUTO_ACK_RE = '~успешно получено и принято в работу|ранее мы направляли вам коммерческое предложение'
        .'|мы отправляли вам коммерческое предложение|напоминаем, что срок действия сч[её]та|к сожалению, срок действия сч[её]та'
        .'|закрыта\. причина:|отправили вам уточняющие вопросы по заявке~iu';

    /** Кроме названия приложенного КП или счёта в письме ничего нет. */
    private const ATTACHMENT_ONLY_RE = '~^(предложение|сч[её]т)\s+мз-\S+\s+от\s+\S+$~iu';

    /** Письма поставщикам (RFQ) — не клиентская переписка. */
    private const RFQ_SUBJECT_RE = '~(^|\s)(request|req\.?|price request)\s+m-\d{4}-\d+|\[rfq|запрос цены~iu';

    public function __construct(
        private readonly EmailTextCleanerService $cleaner,
        private readonly OpenAIChatService $openai,
    ) {}

    /**
     * @return array{candidates: int, reviewed: int, issues: int, failed: int}
     */
    public function run(string $since, string $until, int $limit = 0, int $concurrency = 6, ?callable $progress = null): array
    {
        $letters = $this->candidates($since, $until, $limit);
        $profile = MediaProfileEntry::asBrief();
        $model = (string) config('services.openai.mail_classifier_model', 'gpt-4o-mini');
        $system = OutboundToneAuditPrompt::systemMessage($profile);

        $reviewed = 0;
        $issues = 0;
        $failed = 0;
        $batches = $letters->chunk(self::BATCH)->values();

        foreach ($batches->chunk(max(1, $concurrency)) as $wave) {
            $wave = $wave->values();
            $responses = Http::pool(fn (Pool $pool) => $wave->map(
                fn (Collection $batch, int $i) => $this->request($pool->as((string) $i), $system, $batch, $model)
            )->all());

            foreach ($wave as $i => $batch) {
                $res = $responses[(string) $i] ?? null;
                $content = ($res instanceof \Illuminate\Http\Client\Response && $res->successful())
                    ? (string) ($res->json('choices.0.message.content') ?? '')
                    : null;

                // Пачка не прошла — один повтор обычным путём, с ретраями на 429.
                if ($content === null) {
                    try {
                        $content = (string) ($this->openai->chat($this->messages($system, $batch), $model, $this->options())['content'] ?? '');
                    } catch (\Throwable $e) {
                        Log::warning('ToneAudit: пачка не проверена', ['ids' => $batch->pluck('id')->all(), 'error' => $e->getMessage()]);
                        $failed += $batch->count();

                        continue;
                    }
                }

                [$r, $x] = $this->store($batch, $content, $model);
                $reviewed += $r;
                $issues += $x;
                $failed += $batch->count() - $r;
            }

            if ($progress) {
                $progress($reviewed, $issues, $letters->count());
            }
        }

        return ['candidates' => $letters->count(), 'reviewed' => $reviewed, 'issues' => $issues, 'failed' => $failed];
    }

    /**
     * Письма к проверке с подготовленным текстом.
     *
     * @return Collection<int, array{id: int, request_id: ?int, user_id: ?int, sent_at: mixed, client: string, text: string}>
     */
    public function candidates(string $since, string $until, int $limit = 0): Collection
    {
        $internal = array_map('mb_strtolower', (array) config('services.mail.internal_domains', []));
        $users = User::query()->pluck('id', 'email')->mapWithKeys(fn ($id, $email) => [mb_strtolower((string) $email) => (int) $id]);
        $supplierEmails = Supplier::query()->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower(trim((string) $e)))->flip();
        $supplierDomains = Supplier::query()->whereNotNull('domain')->pluck('domain')->map(fn ($d) => mb_strtolower(trim((string) $d)))->filter()->flip();

        $out = collect();
        EmailMessage::query()
            ->where('direction', MailDirection::Outbound->value)
            ->where('is_draft', false)
            ->whereNotNull('related_request_id')
            ->whereNull('supplier_inquiry_id')
            ->whereBetween('sent_at', [$since, $until])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('outbound_tone_reviews as r')->whereColumn('r.email_message_id', 'email_messages.id'))
            // Успешно закрытые сделки не проверяем (решение заказчика 29.09):
            // клиент купил — тон переписки его не оттолкнул.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('requests as rq')
                ->whereColumn('rq.id', 'email_messages.related_request_id')
                ->whereIn('rq.status', [\App\Enums\RequestStatus::ClosedWon->value, \App\Enums\RequestStatus::Paid->value]))
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$out, $internal, $users, $supplierEmails, $supplierDomains, $limit) {
                foreach ($chunk as $m) {
                    if ($limit > 0 && $out->count() >= $limit) {
                        return false;
                    }
                    $userId = $users[mb_strtolower((string) $m->from_email)] ?? null;
                    if ($userId === null || preg_match(self::RFQ_SUBJECT_RE, (string) $m->subject)) {
                        continue;
                    }
                    $external = array_filter(
                        array_map(fn ($r) => mb_strtolower(trim((string) ($r['email'] ?? ''))), array_merge((array) $m->to_recipients, (array) $m->cc_recipients)),
                        function (string $e) use ($internal, $supplierEmails, $supplierDomains) {
                            $domain = (string) substr((string) strrchr($e, '@'), 1);

                            return $e !== '' && ! in_array($domain, $internal, true)
                                && ! isset($supplierEmails[$e]) && ! isset($supplierDomains[$domain]);
                        },
                    );
                    if ($external === []) {
                        continue;
                    }
                    $text = $this->ownText($m);
                    if (mb_strlen($text) < 3 || preg_match(self::AUTO_ACK_RE, $text) || preg_match(self::ATTACHMENT_ONLY_RE, $text)) {
                        continue;
                    }
                    $out->push([
                        'id' => (int) $m->id,
                        'request_id' => (int) $m->related_request_id,
                        'user_id' => $userId,
                        'sent_at' => $m->sent_at,
                        'client' => $this->clientContext($m),
                        'text' => $text,
                    ]);
                }

                return true;
            });

        return $out;
    }

    /** Собственный текст менеджера: без цитаты, подписи, рекламного блока и служебного хвоста. */
    public function ownText(EmailMessage $m): string
    {
        $text = $this->cleaner->removeSignature($this->cleaner->clientOwnText($m));
        // Подпись, если removeSignature её не узнал, и всё, что ниже.
        $text = preg_split('~^\s*(--\s*$|с уважением|with best regards|best regards)~miu', $text)[0] ?? $text;
        $text = preg_replace('~^\s*№ заявки:.*$~mu', '', $text) ?? $text;
        $text = str_replace('**', '', $text);
        $text = preg_replace("~\n{3,}~u", "\n\n", $text) ?? $text;

        return mb_substr(trim($text), 0, 1500);
    }

    /** Последнее письмо клиента в заявке до этого ответа — чтобы понимать, на что отвечали. */
    private function clientContext(EmailMessage $m): string
    {
        $prev = EmailMessage::query()
            ->where('related_request_id', $m->related_request_id)
            ->where('direction', MailDirection::Inbound->value)
            ->where('sent_at', '<', $m->sent_at)
            ->orderByDesc('sent_at')
            ->first();

        return $prev ? mb_substr(trim($this->cleaner->removeSignature($this->cleaner->clientOwnText($prev))), 0, 600) : '';
    }

    private function request(\Illuminate\Http\Client\PendingRequest $req, string $system, Collection $batch, string $model)
    {
        $headers = ['Authorization' => 'Bearer '.config('services.openai.api_key')];
        if ((string) config('services.openai.proxy_key') !== '') {
            $headers['X-Proxy-Key'] = config('services.openai.proxy_key');
        }

        return $req->withHeaders($headers)->timeout(120)
            ->post(rtrim((string) config('services.openai.base_url'), '/').'/v1/chat/completions', [
                'model' => $model,
                'messages' => $this->messages($system, $batch),
            ] + $this->options());
    }

    /** @return list<array{role: string, content: string}> */
    private function messages(string $system, Collection $batch): array
    {
        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => OutboundToneAuditPrompt::userMessage(
                $batch->map(fn ($l) => ['id' => $l['id'], 'client' => $l['client'], 'text' => $l['text']])->values()->all()
            )],
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return ['response_format' => ['type' => 'json_object'], 'temperature' => 0];
    }

    /**
     * @return array{0: int, 1: int} [проверено, с замечаниями]
     */
    private function store(Collection $batch, string $content, string $model): array
    {
        $parsed = json_decode($content, true);
        $byId = collect($parsed['results'] ?? [])->keyBy(fn ($r) => (int) ($r['id'] ?? 0));
        $reviewed = 0;
        $issues = 0;

        foreach ($batch as $l) {
            $r = $byId[$l['id']] ?? null;
            if ($r === null) {
                continue;
            }
            $cats = array_values(array_intersect(array_keys(OutboundToneReview::CATEGORIES), (array) ($r['categories'] ?? [])));
            $issue = ($r['verdict'] ?? 'ok') === 'issue' && $cats !== [];
            OutboundToneReview::query()->updateOrCreate(['email_message_id' => $l['id']], [
                'request_id' => $l['request_id'],
                'user_id' => $l['user_id'],
                'sent_at' => $l['sent_at'],
                'verdict' => $issue ? 'issue' : 'ok',
                'severity' => $issue ? max(1, min(3, (int) ($r['severity'] ?? 1))) : 0,
                'categories' => $issue ? $cats : null,
                'quote' => $issue ? mb_substr((string) ($r['quote'] ?? ''), 0, 1000) : null,
                'comment' => $issue ? mb_substr((string) ($r['comment'] ?? ''), 0, 1000) : null,
                'suggestion' => $issue ? mb_substr((string) ($r['suggestion'] ?? ''), 0, 2000) : null,
                'model' => $model,
            ]);
            $reviewed++;
            $issues += $issue ? 1 : 0;
        }

        return [$reviewed, $issues];
    }
}
