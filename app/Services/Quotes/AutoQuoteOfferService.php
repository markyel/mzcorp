<?php

namespace App\Services\Quotes;

use App\Models\AutoQuoteSnapshot;
use App\Models\Request;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Collection;

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

        return $draft;
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
        // письму поднимутся статус заявки и признак «КП отправлено».
        $sent = $result['draft'] ?? $draft;
        $hooks = app(\App\Services\Mail\OutboundReplyHooks::class);
        if (! $hooks->applyPostSendHooks($sent, $author)) {
            $hooks->detectOutboundDocuments($sent);
        }

        return ['ok' => true, 'message' => 'КП отправлено клиенту на '.$request->client_email.'.'];
    }
}
