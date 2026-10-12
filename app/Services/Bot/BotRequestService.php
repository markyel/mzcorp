<?php

namespace App\Services\Bot;

use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Jobs\Kb\ResolveKbJob;
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

        $this->createItems($request, $draft, $subject, $chat);

        // Как после разбора письма: распределить, записать начальный переход.
        $manager = $this->assignment->autoAssign($request->fresh());
        if ($manager !== null) {
            try {
                app(RequestStateService::class)->recordSystemInitial($request->fresh(), $manager, 'Авто-распределение заявки из '.$channel.'.');
            } catch (\Throwable $e) {
                Log::warning('BotRequestService: system_initial not recorded', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        }
        if ($request->items()->exists()) {
            ResolveKbJob::dispatch($request->id);
        }

        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'request_id' => $request->id,
            'direction' => 'in',
            'kind' => 'request',
            'text' => $originalText,
        ]);
        $chat->increment('requests_count');

        try {
            $this->mailer->sendMailable((string) $chat->email, new BotRequestReceivedMail($request->fresh(['items']), $channel), BotEmailVerifier::FROM_NAME);
        } catch (\Throwable $e) {
            Log::warning('BotRequestService: receipt email failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
        }

        Log::info('BotRequestService: request created', [
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'chat_id' => $chat->id,
            'channel' => $chat->channel->value,
            'manager_id' => $manager?->id,
        ]);

        return $request->fresh();
    }

    /** @param  list<array{sku: ?string, qty: float, text: string}>  $draft */
    private function createItems(Request $request, array $draft, string $subject, MessengerChat $chat): void
    {
        $position = 0;
        $free = [];
        $catalog = CatalogItem::query()
            ->whereIn('sku', array_values(array_filter(array_column($draft, 'sku'))))
            ->where('is_active', true)
            ->get()->keyBy('sku');

        foreach ($draft as $line) {
            if ($line['sku'] === null) {
                $free[] = $line['text'];

                continue;
            }
            $item = $catalog[$line['sku']] ?? null;
            RequestItem::create([
                'request_id' => $request->id,
                'position' => ++$position,
                'parsed_name' => $item?->name ?: $line['sku'],
                'parsed_article' => $line['sku'],
                'parsed_qty' => $line['qty'],
                'parsed_unit' => 'шт.',
                'catalog_item_id' => $item?->id,
                'data_source' => 'bot',
                'status' => 'parsed',
                'is_active' => true,
            ]);
        }

        if ($free === []) {
            return;
        }
        $text = implode("\n", $free);
        $parsed = [];
        try {
            $parsed = $this->parser->parseItemsWithGPT($text, $subject, (string) $chat->email, $chat->contact_name);
        } catch (\Throwable $e) {
            Log::warning('BotRequestService: free text not parsed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
        }
        // Разборщик ничего не нашёл — позиция с исходным текстом, чтобы
        // менеджер видел, о чём просят, а заявка не осталась без позиций.
        if ($parsed === []) {
            $parsed = [['name' => mb_substr($text, 0, 500), 'qty' => 1]];
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
                'status' => 'parsed',
                'is_active' => true,
            ]);
        }
    }
}
