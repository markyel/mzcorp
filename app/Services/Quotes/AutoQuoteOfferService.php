<?php

namespace App\Services\Quotes;

use App\Enums\RequestStatus;
use App\Models\AutoQuoteSnapshot;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Готовое авто-КП в руках менеджера.
 *
 * Считает и замораживает предложение отдельный конвейер
 * (AutoQuoteSnapshotService, раз в 15 минут). Здесь — то, что видит человек:
 * есть ли по заявке готовое КП, как оно выглядит письмом и отправка его
 * клиенту одним действием.
 *
 * Отправляем только то, что показали. Менеджер видит позиции и суммы на
 * экране, жмёт кнопку — уходит ровно этот текст: письмо собирается из того же
 * снимка, а не пересчитывается заново. Иначе «проверил одно, отправил другое».
 */
class AutoQuoteOfferService
{
    public function __construct(
        private readonly EmailDraftService $drafts,
        private readonly OutgoingMailSender $sender,
    ) {}

    /**
     * Готовое предложение по заявке, если оно есть.
     *
     * Годится только «зелёный» снимок текущей версии правил: если правила
     * с тех пор переписали, показывать старое решение как готовое нельзя.
     */
    public function readyFor(Request $request): ?AutoQuoteSnapshot
    {
        $snapshot = AutoQuoteSnapshot::query()->where('request_id', $request->id)->first();

        if ($snapshot === null
            || ! $snapshot->eligible
            || $snapshot->rule_version !== AutoQuoteSnapshotService::RULE_VERSION
            || ($snapshot->lines ?? []) === []) {
            return null;
        }

        return $snapshot;
    }

    /**
     * Есть ли готовое КП у каждой из заявок — одним запросом, для списков.
     *
     * @param  array<int, int>  $requestIds
     * @return Collection<int, AutoQuoteSnapshot>  ключ — request_id
     */
    public function readyForMany(array $requestIds): Collection
    {
        $requestIds = array_values(array_filter(array_unique($requestIds)));
        if ($requestIds === []) {
            return collect();
        }

        return AutoQuoteSnapshot::query()
            ->whereIn('request_id', $requestIds)
            ->where('eligible', true)
            ->where('rule_version', AutoQuoteSnapshotService::RULE_VERSION)
            ->get()
            ->filter(fn (AutoQuoteSnapshot $s) => ($s->lines ?? []) !== [])
            ->keyBy('request_id');
    }

    /** Тема письма с предложением. */
    public function subject(Request $request): string
    {
        return 'Коммерческое предложение по заявке '.$request->internal_code;
    }

    /**
     * Тело письма: то же, что показано менеджеру на экране.
     */
    public function body(Request $request, AutoQuoteSnapshot $snapshot): string
    {
        $lines = [];
        $lines[] = 'Здравствуйте!';
        $lines[] = '';
        $lines[] = 'По вашему запросу предлагаем:';
        $lines[] = '';

        foreach ($snapshot->lines as $i => $line) {
            $qty = rtrim(rtrim(number_format((float) ($line['qty'] ?? 0), 2, ',', ' '), '0'), ',');
            $lines[] = sprintf(
                '%d. %s%s — %s %s × %s ₽ = %s ₽',
                $i + 1,
                (string) ($line['name'] ?? ''),
                ($line['sku'] ?? '') !== '' ? ' (арт. '.$line['sku'].')' : '',
                $qty,
                (string) ($line['unit'] ?? 'шт.'),
                number_format((float) ($line['unit_price'] ?? 0), 2, ',', ' '),
                number_format((float) ($line['total'] ?? 0), 2, ',', ' '),
            );
        }

        $lines[] = '';
        $lines[] = 'Итого: '.number_format((float) $snapshot->total, 2, ',', ' ').' ₽';
        $lines[] = '';
        $lines[] = 'Товар на складе, счёт выставим в день обращения.';
        $lines[] = 'Готовы ответить на вопросы и подготовить счёт.';

        return implode("\n", $lines);
    }

    /**
     * Создать черновик письма с предложением — для правки перед отправкой.
     */
    public function draft(Request $request, AutoQuoteSnapshot $snapshot, User $author)
    {
        $draft = $this->drafts->createCompose($request, $author);

        $draft->subject = $this->subject($request);
        $draft->body_plain = $this->body($request, $snapshot);
        $draft->body_html = nl2br(e($draft->body_plain));
        $draft->save();

        $this->attachPdf($draft, $request, $snapshot, $author);

        return $draft;
    }

    /**
     * Приложить КП файлом.
     *
     * Клиент должен получить документ, а не письмо с табличкой в тексте:
     * его пересылают снабженцу, печатают, прикладывают к заявке у себя.
     * Подпись и адрес отправителя подставит отправка — черновик создан от
     * имени менеджера, а значит уйдёт с его ящика.
     */
    private function attachPdf(EmailMessage $draft, Request $request, AutoQuoteSnapshot $snapshot, User $author): void
    {
        try {
            $pdf = app(AutoQuotePdfService::class);
            $content = $pdf->render($request, $snapshot, $author);
            $name = $pdf->filename($request);

            $path = sprintf('mail/%d/drafts/%d/%s', $draft->mailbox_id ?? 0, $draft->id, Str::random(8).'_quote.pdf');
            Storage::disk('local')->put($path, $content);

            EmailAttachment::create([
                'email_message_id' => $draft->id,
                'filename' => mb_substr($name, 0, 255),
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($content),
                'content_id' => null,
                'file_path' => $path,
                'disk' => 'local',
                'is_inline' => false,
            ]);
        } catch (\Throwable $e) {
            // Без файла письмо всё равно имеет смысл: позиции и суммы есть в
            // тексте. Роняем только вложение, не отправку.
            Log::error('AutoQuoteOfferService: не удалось собрать PDF предложения', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Отправить предложение клиенту.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(Request $request, User $author): array
    {
        $snapshot = $this->readyFor($request);
        if ($snapshot === null) {
            return ['ok' => false, 'message' => 'По этой заявке готового авто-КП нет.'];
        }
        if (trim((string) $request->client_email) === '') {
            return ['ok' => false, 'message' => 'У заявки нет адреса клиента — отправлять некуда.'];
        }

        $draft = $this->draft($request, $snapshot, $author);
        $result = $this->sender->sendDraft($draft->id);

        if (! ($result['success'] ?? false)) {
            return ['ok' => false, 'message' => 'Письмо не ушло: '.(string) ($result['error'] ?? 'неизвестная ошибка')];
        }

        // Тот же путь, что и у обычного ответа менеджера: по отправленному
        // письму поднимутся признаки и сработают детекторы.
        $sent = $result['draft'] ?? $draft;
        $hooks = app(\App\Services\Mail\OutboundReplyHooks::class);
        if (! $hooks->applyPostSendHooks($sent, $author)) {
            $hooks->detectOutboundDocuments($sent);
        }

        $this->markQuoted($request, $author);

        return ['ok' => true, 'message' => 'КП отправлено клиенту на '.$request->client_email.'.'];
    }

    /**
     * Перевести заявку в «КП отправлено».
     *
     * Детектор исходящих документов ставит этот статус по распознанному
     * вложению — здесь распознавать нечего: мы сами собрали документ с
     * позициями и суммой и сами его отправили. Ставим прямо, не дожидаясь,
     * пока разбор догадается.
     */
    private function markQuoted(Request $request, User $author): void
    {
        $request = $request->fresh();
        if ($request === null || $request->status === RequestStatus::Quoted) {
            return;
        }

        try {
            app(\App\Services\Request\RequestStateService::class)->transitionTo(
                $request,
                RequestStatus::Quoted,
                $author,
                ['source' => 'auto_quote_sent'],
                systemTransition: true,
            );
        } catch (\Throwable $e) {
            // Письмо клиенту уже ушло — статус не повод показывать ошибку.
            Log::warning('AutoQuoteOfferService: статус «КП отправлено» не выставлен', [
                'request_id' => $request->id,
                'from' => $request->status?->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
