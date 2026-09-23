<?php

namespace App\Services\Quotes;

use App\Enums\RequestStatus;
use App\Models\AutoQuoteSnapshot;
use App\Models\Quotation;
use App\Models\Request;
use App\Models\User;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Quotations\QuotationDispatchService;
use App\Services\Quotations\QuotationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Готовое авто-КП в руках менеджера.
 *
 * Считает и замораживает предложение отдельный конвейер
 * (AutoQuoteSnapshotService). Здесь — то, что видит человек: есть ли по заявке
 * готовое КП и отправка его клиенту одним действием.
 *
 * Документ, письмо и статусы — тем же путём, что у КП, собранного руками:
 * QuotationService создаёт КП по позициям заявки, QuotationDispatchService
 * делает PDF по шаблону, кладёт его в ответ в треде и ставит пометку, по
 * которой post-send hook переведёт КП в «отправлено», а заявку — в «КП
 * отправлено». Своего документа у авто-КП нет и быть не должно: клиент не
 * должен по виду письма понимать, робот ему ответил или менеджер.
 */
class AutoQuoteOfferService
{
    /** Насколько сумма собранного КП может разойтись со снимком, рублей. */
    public const TOTAL_TOLERANCE = 0.05;

    public function __construct(
        private readonly QuotationService $quotations,
        private readonly QuotationDispatchService $dispatch,
        private readonly OutgoingMailSender $sender,
    ) {}

    /**
     * Готовое предложение по заявке, если оно есть.
     *
     * Годится только «зелёный» снимок текущей версии правил: если правила с
     * тех пор переписали, показывать старое решение как готовое нельзя.
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

        // Цену клиенту уже назвали — предлагать «отправить КП» второй раз
        // нельзя: менеджер нажмёт и отправит дубль. Дальше по заявке идёт
        // обычная работа, а новый вариант делается через реестр КП.
        if (self::alreadyQuoted($request)) {
            return null;
        }

        return $snapshot;
    }

    /** По заявке уже выдано КП: отправленное предложение или статус. */
    public static function alreadyQuoted(Request $request): bool
    {
        if (in_array($request->status, self::QUOTED_STATUSES, true)) {
            return true;
        }

        return Quotation::query()
            ->where('request_id', $request->id)
            ->whereIn('status', self::SENT_QUOTATION_STATUSES)
            ->exists();
    }

    /** Статусы заявки, в которых цена клиенту уже названа. */
    private const QUOTED_STATUSES = [
        RequestStatus::Quoted,
        RequestStatus::UnderReview,
        RequestStatus::AwaitingInvoice,
        RequestStatus::Invoiced,
        RequestStatus::Paid,
        RequestStatus::ClosedWon,
        RequestStatus::ClosedLost,
    ];

    /** Статусы КП, означающие «клиент это уже получил». */
    private const SENT_QUOTATION_STATUSES = ['sent', 'accepted', 'rejected'];

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

        // Те же отсечения, что и поштучно, но без запроса на каждую строку
        // списка: заявки с уже названной ценой и с отправленным КП вон.
        $quotedByStatus = Request::query()
            ->whereIn('id', $requestIds)
            ->whereIn('status', array_map(fn (RequestStatus $s) => $s->value, self::QUOTED_STATUSES))
            ->pluck('id')
            ->all();

        $quotedByDocument = Quotation::query()
            ->whereIn('request_id', $requestIds)
            ->whereIn('status', self::SENT_QUOTATION_STATUSES)
            ->pluck('request_id')
            ->all();

        $skip = array_flip(array_merge($quotedByStatus, $quotedByDocument));

        return AutoQuoteSnapshot::query()
            ->whereIn('request_id', $requestIds)
            ->where('eligible', true)
            ->where('rule_version', AutoQuoteSnapshotService::RULE_VERSION)
            ->get()
            ->filter(fn (AutoQuoteSnapshot $s) => ($s->lines ?? []) !== []
                && ! isset($skip[(int) $s->request_id]))
            ->keyBy('request_id');
    }

    /**
     * Собрать КП по заявке — обычное, в реестре КП, с номером и версией.
     *
     * Сумма сверяется со снимком, который менеджер видел на экране: если
     * разошлась (цену в каталоге успели поменять), отправлять нельзя —
     * человек одобрил другую цифру.
     *
     * @return array{ok: bool, message: string, quotation: ?\App\Models\Quotation}
     */
    public function buildQuotation(Request $request, AutoQuoteSnapshot $snapshot, User $author): array
    {
        $quotation = $this->quotations->createDraft($request, $author);

        $diff = abs((float) $quotation->total - (float) $snapshot->total);
        if ($diff > self::TOTAL_TOLERANCE) {
            return [
                'ok' => false,
                'quotation' => $quotation,
                'message' => sprintf(
                    'Сумма изменилась с момента расчёта: на экране %s ₽, в собранном КП %s ₽. '
                    .'КП %s создано черновиком — проверьте его и отправьте вручную.',
                    number_format((float) $snapshot->total, 2, ',', ' '),
                    number_format((float) $quotation->total, 2, ',', ' '),
                    $quotation->internal_code,
                ),
            ];
        }

        return ['ok' => true, 'quotation' => $quotation, 'message' => ''];
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

        $built = $this->buildQuotation($request, $snapshot, $author);
        if (! $built['ok']) {
            return ['ok' => false, 'message' => $built['message']];
        }

        try {
            $prepared = $this->dispatch->prepareDraft($built['quotation'], $author);
        } catch (\Throwable $e) {
            Log::error('AutoQuoteOfferService: письмо с КП не собрано', [
                'request_id' => $request->id,
                'quotation_id' => $built['quotation']->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Не удалось собрать письмо с КП: '.$e->getMessage()];
        }

        $result = $this->sender->sendDraft($prepared['draft']->id);
        if (! ($result['success'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'Письмо не ушло: '.(string) ($result['error'] ?? 'неизвестная ошибка')
                    .'. КП '.$built['quotation']->internal_code.' осталось черновиком.',
            ];
        }

        // Тот же post-send hook, что у ручной отправки: по пометке
        // quotation_sent КП станет «отправлено», а заявка — «КП отправлено».
        $sent = $result['draft'] ?? $prepared['draft'];
        $hooks = app(\App\Services\Mail\OutboundReplyHooks::class);
        if (! $hooks->applyPostSendHooks($sent, $author)) {
            $hooks->detectOutboundDocuments($sent);
        }

        return [
            'ok' => true,
            'message' => 'КП '.$built['quotation']->internal_code.' отправлено на '.$request->client_email.'.',
        ];
    }

    /**
     * Собрать КП и черновик письма, не отправляя, — если менеджер хочет
     * поправить текст. Возвращает идентификатор черновика.
     */
    public function draft(Request $request, AutoQuoteSnapshot $snapshot, User $author): ?int
    {
        $built = $this->buildQuotation($request, $snapshot, $author);

        try {
            return $this->dispatch->prepareDraft($built['quotation'], $author)['draft']->id;
        } catch (\Throwable $e) {
            Log::error('AutoQuoteOfferService: черновик КП не собран', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
