<?php

namespace App\Services\Bot;

use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Mail\BotRequestReceivedMail;
use App\Models\CatalogItem;
use App\Models\MessengerChat;
use App\Models\MessengerMessage;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Clients\RequestOrganizationResolver;
use App\Services\Mail\SystemNotificationMailer;
use App\Services\Request\AssignmentService;
use App\Services\Request\InternalCodeGenerator;
use App\Services\Request\RequestActivityService;
use App\Services\Request\RequestStateService;
use App\Services\RequestItemParsingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Заявка из бота: черновик чата (строки с M-артикулами и свободный текст) →
 * обычная заявка M-…, как из письма, но без письма-источника.
 *
 * Строки с артикулом сразу привязываются к каталогу; свободный текст
 * разбирает тот же разборщик, что и письма. Дальше — то же, что после
 * разбора письма: автораспределение, начальная запись истории статусов,
 * сопоставление с базой знаний. Письма-источника нет, поэтому «заявка
 * принята» уходит клиенту отдельным письмом на подтверждённый адрес.
 */
class BotRequestService
{
    public function __construct(
        private readonly InternalCodeGenerator $codes,
        private readonly RequestOrganizationResolver $orgResolver,
        private readonly RequestActivityService $activity,
        private readonly AssignmentService $assignment,
        private readonly RequestItemParsingService $parser,
        private readonly SystemNotificationMailer $mailer,
        private readonly BotIntakeStore $store,
    ) {}

    /**
     * Строки черновика: позиции с артикулом и количеством либо свободный текст.
     *
     * @return array{sku: ?string, qty: float, text: string}
     */
    public static function parseLine(string $line): array
    {
        $line = trim($line);
        $skus = BotPriceService::skusIn($line);
        if (count($skus) !== 1) {
            return ['sku' => null, 'qty' => 1.0, 'text' => $line];
        }
        // Количество — первое число после артикула: «M12345 5», «M12345 x 5 шт», «M12345 - 2».
        $after = preg_replace(BotPriceService::SKU_RE, '', $line, 1) ?? '';
        $qty = preg_match('/(\d+(?:[.,]\d+)?)/u', $after, $m) ? (float) str_replace(',', '.', $m[1]) : 1.0;

        return ['sku' => $skus[0], 'qty' => $qty > 0 && $qty <= 100000 ? $qty : 1.0, 'text' => $line];
    }

    public function submit(MessengerChat $chat): Request
    {
        /** @var list<array{sku: ?string, qty: float, text: string}> $draft */
        $draft = array_values((array) $chat->stateGet('draft', []));
        $channel = $chat->channel->label();
        $company = trim((string) ($chat->organization?->name ?: $chat->company_name));
        $subject = 'Заявка из '.$channel.($company !== '' ? ' — '.$company : '');
        $originalText = implode("\n", array_map(fn ($l) => $l['text'], $draft));

        $request = DB::transaction(function () use ($chat, $subject, $originalText) {
            $req = Request::create([
                'internal_code' => $this->codes->next(),
                'email_message_id' => null,
                // Pending до появления позиций — как у заявки из письма.
                'status' => RequestStatus::Pending,
                'subject' => $subject,
                'client_email' => (string) $chat->email,
                // Анкету могли пропустить — тогда имя из профиля мессенджера.
                'client_name' => $chat->contact_name ?: (trim($chat->first_name.' '.$chat->last_name) ?: null),
                'client_phone' => $chat->phone,
                'client_company' => $chat->organization?->name ?: $chat->company_name,
                'organization_id' => $chat->organization_id,
                'source' => $chat->channel->value,
                'messenger_chat_id' => $chat->id,
                'parsing_meta' => ['bot' => ['channel' => $chat->channel->value, 'text' => $originalText]],
            ]);
            if ($req->organization_id === null) {
                $this->orgResolver->attach($req);
            }
            $this->activity->touch($req, RequestActivityType::RequestCreated);

            return $req;
        });

        // Позиции по M-артикулам из каталога — сразу; остальное разберёт
        // finalize() в очереди: распознавание фото и файлов идёт минуту, а
        // клиент должен получить номер заявки сразу.
        [$linked, $unlinkedText] = $this->createLinkedItems($request, $draft);

        // Фото и файлы клиента — вложения «письма»-носителя (BotIntakeStore):
        // так они видны во вкладках «Переписка» и «Файлы» и в экспорте.
        /** @var list<array{path: string, name: string, mime: ?string, size: int}> $files */
        $files = array_values((array) $chat->stateGet('draft_files', []));
        if ($files !== [] || trim($unlinkedText) !== '') {
            $this->store->carrier($request, $chat, $originalText, $files);
        }
        $meta = (array) $request->parsing_meta;
        $meta['bot'] = array_merge((array) ($meta['bot'] ?? []), ['linked' => $linked, 'unlinked_text' => $unlinkedText, 'files' => count($files)]);
        $request->forceFill(['parsing_meta' => $meta])->save();

        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'request_id' => $request->id,
            'direction' => 'in',
            'kind' => 'request',
            'text' => $originalText,
            'meta' => $files !== [] ? ['files' => array_column($files, 'name')] : null,
        ]);
        $chat->increment('requests_count');

        \App\Jobs\Bot\FinalizeBotRequestJob::dispatch($request->id);

        Log::info('BotRequestService: request created', [
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'chat_id' => $chat->id,
            'channel' => $chat->channel->value,
            'files' => count($files),
        ]);

        return $request->fresh();
    }

    /**
     * В очереди после submit(): разобрать текст, фото и файлы в позиции,
     * распределить заявку (как после разбора письма) и отправить клиенту
     * письмо «заявка принята». Сопоставление позиций с каталогом не
     * запускаем (решение заказчика 12.10.2026): задача бота — список позиций
     * и файлы, подбор — менеджер.
     */
    public function finalize(Request $request): void
    {
        $bot = (array) (($request->parsing_meta ?? [])['bot'] ?? []);
        $channel = \App\Enums\MessengerChannel::tryFrom((string) $request->source)?->label() ?? 'бота';
        $carrier = \App\Models\EmailMessage::query()->with('attachments')
            ->where('related_request_id', $request->id)
            ->where('message_id', 'bot-'.$request->id.'@mzcorp.ru')
            ->first();

        if ($carrier !== null && ! $request->items()->where('data_source', 'bot')->whereNull('catalog_item_id')->exists()) {
            $this->parseRest($request, $carrier, (array) ($bot['linked'] ?? []), (string) ($bot['unlinked_text'] ?? ''));
        }

        $request->refresh();
        $manager = $request->assigned_user_id ? $request->assignedUser : $this->assignment->autoAssign($request);
        if ($manager !== null && $request->assigned_user_id === null) {
            try {
                app(RequestStateService::class)->recordSystemInitial($request->fresh(), $manager, 'Авто-распределение заявки из '.$channel.'.');
            } catch (\Throwable $e) {
                Log::warning('BotRequestService: system_initial not recorded', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        }

        $chat = $request->messengerChat;
        if ($chat !== null && empty($bot['receipt_sent'])) {
            try {
                $this->mailer->sendMailable((string) $chat->email, new BotRequestReceivedMail($request->fresh(['items']), $channel), BotEmailVerifier::FROM_NAME);
                $meta = (array) $request->fresh()->parsing_meta;
                $meta['bot']['receipt_sent'] = true;
                $request->forceFill(['parsing_meta' => $meta])->save();
            } catch (\Throwable $e) {
                Log::warning('BotRequestService: receipt email failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('BotRequestService: request finalized', [
            'request_id' => $request->id,
            'items' => $request->items()->count(),
            'manager_id' => $manager?->id,
        ]);
    }

    /**
     * Позиции заявки. Строка с M-артикулом из каталога — позиция с этой
     * позицией каталога (это не подбор, клиент назвал её сам). Всё остальное —
     * текст, фото, Excel/PDF/Word — разбирает тот же разборщик, что и письма
     * (parseItemsFromInboundMessage по «письму»-носителю); с каталогом не
     * сопоставляем.
     *
     * @param  list<array{sku: ?string, qty: float, text: string}>  $draft
     * @return array{0: list<string>, 1: string} артикулы созданных позиций и текст, который ещё разбирать
     */
    private function createLinkedItems(Request $request, array $draft): array
    {
        $position = 0;
        $catalog = CatalogItem::query()
            ->whereIn('sku', array_values(array_filter(array_column($draft, 'sku'))))
            ->where('is_active', true)
            ->get()->keyBy('sku');

        $linked = [];
        foreach ($draft as $line) {
            $item = $line['sku'] !== null ? ($catalog[$line['sku']] ?? null) : null;
            if ($item === null) {
                continue;
            }
            $linked[] = mb_strtoupper($line['sku']);
            RequestItem::create([
                'request_id' => $request->id,
                'position' => ++$position,
                'parsed_name' => $item->name ?: $line['sku'],
                'parsed_article' => $line['sku'],
                'parsed_qty' => $line['qty'],
                'parsed_unit' => 'шт.',
                'catalog_item_id' => $item->id,
                'data_source' => 'bot',
                'status' => 'parsed',
                'is_active' => true,
            ]);
        }

        // Свободный текст и артикулы не из каталога — разберёт parseRest().
        $unlinkedText = collect($draft)->filter(fn ($l) => $l['sku'] === null || ! isset($catalog[$l['sku']]))->pluck('text')->implode("\n");

        return [$linked, $unlinkedText];
    }

    /** @param  list<string>  $linked */
    private function parseRest(Request $request, \App\Models\EmailMessage $carrier, array $linked, string $unlinkedText): void
    {
        if (trim($unlinkedText) === '' && $carrier->attachments->isEmpty()) {
            return;
        }
        $position = (int) $request->items()->max('position');
        $parsed = [];
        try {
            $parsed = $this->parser->parseItemsFromInboundMessage($carrier);
        } catch (\Throwable $e) {
            Log::warning('BotRequestService: items not parsed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
        }
        // Носитель содержит и строки с артикулами — их позиции уже созданы.
        $parsed = array_values(array_filter($parsed, function ($p) use ($linked) {
            $sku = BotPriceService::skusIn((string) ($p['article'] ?? ''))[0] ?? null;

            return $sku === null || ! in_array(mb_strtoupper($sku), $linked, true);
        }));
        // Разборщик ничего не нашёл — позиция-указатель, чтобы менеджер видел,
        // о чём просят, а заявка не осталась без позиций.
        if ($parsed === []) {
            $parsed = [['name' => trim($unlinkedText) !== ''
                ? mb_substr(trim($unlinkedText), 0, 500)
                : 'Позиции — во вложениях заявки ('.$carrier->attachments->count().' файл.)', 'qty' => 1]];
        }
        foreach ($parsed as $p) {
            RequestItem::create([
                'request_id' => $request->id,
                'position' => ++$position,
                'parsed_name' => (string) ($p['name'] ?? ''),
                'parsed_brand' => $p['brand'] ?? null,
                'parsed_article' => $p['article'] ?? null,
                'parsed_qty' => $p['qty'] ?? 1,
                'parsed_unit' => $p['unit'] ?? 'шт.',
                'parsed_length' => $p['length'] ?? null,
                'parsed_length_unit' => $p['length_unit'] ?? null,
                'category' => $p['category'] ?? null,
                'supplier_note' => $p['note'] ?? null,
                'data_source' => 'bot',
                'image_attachment_id' => $p['email_attachment_id'] ?? null,
                'source_email_message_id' => $carrier->id,
                'status' => 'parsed',
                'is_active' => true,
            ]);
        }
    }
}
