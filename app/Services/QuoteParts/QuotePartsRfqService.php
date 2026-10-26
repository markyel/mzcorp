<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogItem;
use App\Models\QuotePartsRfq;
use App\Models\Request as RequestModel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Запрос поставщикам через QuoteParts — второй канал рядом со старой рассылкой.
 *
 * Позиции — только с M-артикулом (каталог): перед запросом они добавляются в пул и сразу
 * передаются. Поставщики — любые из справочника: не принявшие приглашение получают запрос
 * в режиме переписки. Письмо поставщику QuoteParts отправляет сам (EN + ZH, с ящика MyZip на платформе); ответы — формой или
 * письмом — возвращаются сюда снимком (refresh) и показываются в заявке.
 */
class QuotePartsRfqService
{
    public function __construct(
        private readonly QuotePartsClient $client,
        private readonly QuotePartsPoolService $pool,
        private readonly QuotePartsSupplierService $suppliers,
        private readonly QuotePartsDraftService $drafts,
    ) {}

    /**
     * @param  array<int, float>  $quantities  catalog_item_id => количество
     * @param  list<int>  $supplierIds
     * @param  array<int, float>  $draftQuantities  request_item_id => количество: новые товары без
     *                                               M-артикула уходят предварительными позициями
     * @param  array<int, array{path:string, name:string, mime:string, size?:int, disk?:string}>  $files  вложения запроса
     *                                                                                                 (фото позиций, файлы) — в письмо и в кабинет поставщика
     * @param  array<string, string>  $notes  примечание к позиции для поставщика («c:{catalog_item_id}» или
     *                                        «d:{request_item_id}»): длина, количество текстом — в письме и форме
     * @param  array<int, array{lang:string, subject:string, html:string, plain:string}>  $letters  внесистемные поставщики
     *         (supplier_id => письмо по старому алгоритму, SupplierDispatchService/SupplierProcurementDispatchService::composeLetter):
     *         QuoteParts шлёт его как есть с ящика внесистемных (orders@) от имени автора, с его подписью, без формы
     *         и кабинета; ответы поставщиков — копией автору (решение 2026-10-21)
     * @param  bool  $automatic  автоматический перезапрос (мониторинг цен): ящик поставщика выбирает QuoteParts по его
     *                           режиму (главный / по очереди); иначе запрос уходит только на отмеченные адреса
     */
    public function send(?RequestModel $request, array $quantities, array $supplierIds, ?string $replyBy, ?string $message, User $by, array $draftQuantities = [], bool $routed = false, array $files = [], array $notes = [], array $letters = [], bool $automatic = false): QuotePartsRfq
    {
        // routed — поставщики, принявшие приглашение, из обычной рассылки (QuotePartsRouting): у любого сотрудника.
        if ($routed ? ! QuotePartsRouting::enabled() : ! QuotePartsPilot::enabledFor($by)) {
            throw new RuntimeException('QuoteParts выключен или вы не участник пилота.');
        }

        $catalog = CatalogItem::query()->whereIn('id', array_keys($quantities))->get(['id', 'sku'])->keyBy('id');
        // Любой поставщик из справочника (решение 15.10): кто ещё не в QuoteParts — заводится
        // в режиме переписки, без письма-приглашения. Поставщик в QuoteParts — организация:
        // несколько её адресов дают одного получателя; письмо — на отмеченные адреса (addresses).
        $rows = Supplier::query()->with('organization:id,name')->whereIn('id', $supplierIds)->whereNotNull('email')->get();
        if (($catalog->isEmpty() && $draftQuantities === []) || $rows->isEmpty()) {
            throw new RuntimeException('Нужны позиции и хотя бы один поставщик с адресом.');
        }
        foreach ($rows as $row) {
            $this->suppliers->ensureRegistered($row);
        }
        $suppliers = $rows->map->quotepartsKey()->unique()->values();

        $items = [];
        if ($catalog->isNotEmpty()) {
            $statuses = $this->pool->ensureInPool($catalog->keys()->all(), $by);
            $notReady = $catalog->filter(fn ($c) => ($statuses[$c->id] ?? null) !== 'active');
            if ($notReady->isNotEmpty()) {
                throw new RuntimeException('Позиции не в работе в QuoteParts (на паузе или не переданы): '.$notReady->pluck('sku')->implode(', ').'.');
            }
            foreach ($catalog as $c) {
                $items[] = ['code' => $c->sku, 'quantity' => max(0.001, (float) $quantities[$c->id]), 'note' => self::note($notes['c:'.$c->id] ?? null)];
            }
        }
        if ($draftQuantities !== []) {
            foreach ($this->drafts->ensureDrafts(array_keys($draftQuantities)) as $requestItemId => $code) {
                $items[] = ['code' => $code, 'quantity' => max(0.001, (float) $draftQuantities[$requestItemId]), 'note' => self::note($notes['d:'.$requestItemId] ?? null)];
            }
        }

        $payload = [
            'items' => $items,
            'suppliers' => $suppliers->all(),
            // Один запрос — один ящик (решение 26.10.2026): отмеченный менеджером адрес, напоминания — туда же.
            // Автоматический перезапрос адресов не передаёт — ящик выбирает режим поставщика в QuoteParts.
            'addresses' => $automatic ? null : self::addresses($rows),
            'reply_by' => $replyBy,
            'message' => $message ?: null,
            'external_ref' => $request?->internal_code,
            // В теме письма, как у прямого запроса из mzCorp: «[M-2026-19835] / [370918] [QP-…]» —
            // поставщик и менеджер находят переписку по номеру заявки и запроса 1С.
            'subject_refs' => self::subjectRefs($request?->internal_code, $request?->onec_number),
            // Дублирование переписки — настройка сотрудника (раздел QuoteParts → «Пилот»):
            // ответы поставщиков — копией автору, ответ на копию уходит поставщику.
            'mirror' => $by->quoteparts_mirror ? ['email' => $this->mirrorAddress($by), 'name' => $by->name] : null,
            'attachments' => $this->attachments($files),
        ];
        if ($letters !== []) {
            // Внесистемные: письмо как по старому алгоритму, с подписью автора; копии ответов — автору всегда.
            $payload += [
                'channel' => 'offsystem',
                'from_name' => $by->name,
                'signature' => $this->signature($by, offsystem: true),
                'letters' => $this->offsystemLetters($letters, $rows, $by),
            ];
            $payload['mirror'] = ['email' => $this->mirrorAddress($by), 'name' => $by->name];
        }
        $result = $this->client->createRfq($payload);

        return QuotePartsRfq::create([
            'remote_id' => $result['id'],
            'tag' => $result['tag'] ?? null,
            'request_id' => $request?->id,
            'created_by_user_id' => $by->id,
            'reply_by' => $result['reply_by'] ?? $replyBy,
            'status' => 'open',
            'snapshot' => $result,
            'synced_at' => now(),
        ]);
    }

    /**
     * Отмеченные адреса по ключу поставщика в QuoteParts: два адреса одной организации — оба её получателя.
     *
     * @param  \Illuminate\Support\Collection<int, Supplier>  $rows
     * @return array<string, list<string>>
     */
    public static function addresses(\Illuminate\Support\Collection $rows): array
    {
        return $rows->filter(fn (Supplier $s) => trim((string) $s->email) !== '')
            ->groupBy(fn (Supplier $s) => $s->quotepartsKey())
            ->map(fn ($group) => $group->map(fn (Supplier $s) => mb_strtolower(trim((string) $s->email)))->unique()->values()->all())
            ->all();
    }

    /**
     * Вложения запроса для API: base64, в пределах лимита платформы (лишнее не отправляем, а пишем в лог).
     *
     * @param  array<int, array{path:string, name:string, mime:string, size?:int, disk?:string}>  $files
     * @return list<array{name:string, mime:string, content:string}>
     */
    private function attachments(array $files): array
    {
        $out = [];
        $total = 0;
        $limit = (int) config('services.quoteparts.attachments_max_bytes', 15 * 1024 * 1024);
        foreach ($files as $f) {
            try {
                $bytes = \Illuminate\Support\Facades\Storage::disk($f['disk'] ?? 'local')->get((string) $f['path']);
            } catch (Throwable) {
                $bytes = null;
            }
            if ($bytes === null || $bytes === '') {
                continue;
            }
            if ($total + strlen($bytes) > $limit) {
                Log::warning('QuoteParts RFQ: attachment skipped over the size limit', ['file' => $f['name'] ?? null, 'size' => strlen($bytes)]);

                continue;
            }
            $total += strlen($bytes);
            $out[] = ['name' => mb_substr((string) ($f['name'] ?? 'file'), 0, 200), 'mime' => (string) ($f['mime'] ?? 'application/octet-stream'), 'content' => base64_encode($bytes)];
        }

        return $out;
    }

    /**
     * Куда дублировать переписку: личный ящик автора (с него он и ответит), иначе адрес входа.
     */
    public function mirrorAddress(User $user): string
    {
        $personal = \App\Models\Mailbox::query()
            ->where('owner_user_id', $user->id)->where('type', \App\Enums\MailboxType::Personal->value)->where('is_active', true)
            ->orderBy('id')->value('email');

        return (string) ($personal ?: $user->email);
    }

    /**
     * Названия поставщиков для снимков запросов: external_id в QuoteParts — «org:{id}»
     * (организация) или «sup:{id}» (отдельный адрес).
     *
     * @param  iterable<QuotePartsRfq>  $rfqs
     * @return \Illuminate\Support\Collection<string, string>
     */
    private static function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }

    /** Единицы длины из заявки — латиницей: примечание читает иностранный поставщик. */
    private const LENGTH_UNITS = ['м' => 'm', 'мм' => 'mm', 'см' => 'cm', 'м.' => 'm', 'п.м' => 'm', 'пм' => 'm'];

    /**
     * Количество и примечание позиции для запроса QuoteParts. В рассылке поле «Кол-во» — свободный
     * текст (в письмо по старому алгоритму он уходит как есть, например «21,710 mm»): число —
     * количеством; иное — количество из заявки, а текст примечанием. Длина из заявки (мерная
     * позиция: поручень, канат) уходит примечанием всегда, если менеджер её сам не вписал.
     *
     * @return array{quantity: float, note: ?string}
     */
    public static function quantityAndNote(?string $typed, float $parsedQty, ?float $length = null, ?string $lengthUnit = null): array
    {
        $typed = trim((string) $typed);
        $quantity = $parsedQty > 0 ? $parsedQty : 1.0;
        $note = null;

        if ($typed !== '') {
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(шт\.?|pcs?\.?|units?|ед\.?)?$/iu', $typed, $m)) {
                $q = (float) str_replace(',', '.', $m[1]);
                $quantity = $q > 0 ? $q : $quantity;
            } else {
                $note = 'Qty: '.$typed;
            }
        }

        if ($note === null && $length !== null && $length > 0) {
            $unit = trim((string) $lengthUnit);
            $unit = self::LENGTH_UNITS[mb_strtolower($unit)] ?? $unit;
            $note = 'Length: '.rtrim(rtrim(number_format($length, 3, '.', ''), '0'), '.').($unit !== '' ? ' '.$unit : '');
        }

        return ['quantity' => $quantity, 'note' => $note];
    }

    public function supplierNames(iterable $rfqs): \Illuminate\Support\Collection
    {
        $keys = collect($rfqs)->flatMap(fn ($r) => collect($r->snapshot['suppliers'] ?? [])->pluck('external_id'))->filter()->unique();
        $orgIds = $keys->filter(fn ($k) => str_starts_with($k, 'org:'))->map(fn ($k) => (int) substr($k, 4));
        $supIds = $keys->filter(fn ($k) => str_starts_with($k, 'sup:'))->map(fn ($k) => (int) substr($k, 4));

        return \App\Models\SupplierOrganization::query()->whereIn('id', $orgIds)->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => ['org:'.$id => $n])
            ->merge(Supplier::query()->whereIn('id', $supIds)->get(['id', 'name', 'email'])->mapWithKeys(fn ($s) => ['sup:'.$s->id => $s->name ?: $s->email]));
    }

    /**
     * Ответ поставщику из mzcorp: QuoteParts отправит письмо от MyZip в переписку поставщика.
     * Поставщик — по порядку в снимке запроса.
     */
    /**
     * Как ответ в классической переписке с поставщиком (раздел «Поставщики»): файлы, копия,
     * подпись автора; цитату письма поставщика и тему «Re:» добавляет QuoteParts.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $files
     */
    public function answer(QuotePartsRfq $rfq, int $supplierIndex, string $text, User $by, array $files = [], string $cc = '', bool $withSignature = true): QuotePartsRfq
    {
        if (! QuotePartsPilot::canAnswer($by)) {
            throw new RuntimeException('Отвечать поставщикам QuoteParts могут снабжение, руководство и участники пилота.');
        }
        $externalId = $rfq->snapshot['suppliers'][$supplierIndex]['external_id'] ?? null;
        if ($externalId === null || trim($text) === '') {
            throw new RuntimeException('Напишите ответ поставщику.');
        }

        $snapshot = $this->client->answerSupplier($rfq->remote_id, $externalId, trim($text), $by->name, [
            'files' => $this->uploadedFiles($files),
            'cc' => self::parseCc($cc),
            'signature' => $withSignature ? $this->signature($by, offsystem: ($rfq->snapshot['channel'] ?? null) === 'offsystem') : null,
        ]);
        $rfq->forceFill([
            'snapshot' => $snapshot,
            'status' => $snapshot['status'] ?? $rfq->status,
            'synced_at' => now(),
        ])->save();

        return $rfq;
    }

    /**
     * Язык общения поставщиков по ключам QuoteParts (org:{id} / sup:{id}) — из настроек
     * поставщика в mzcorp, как в запросе из «Снабжения»: en, если у организации хоть один
     * адрес англоязычный, иначе ru.
     *
     * @param  iterable<string>  $keys
     * @return array<string, string>
     */
    public function supplierLanguages(iterable $keys): array
    {
        $keys = collect($keys)->filter()->unique()->values();
        $orgIds = $keys->filter(fn ($k) => str_starts_with($k, 'org:'))->map(fn ($k) => (int) substr($k, 4));
        $supIds = $keys->filter(fn ($k) => str_starts_with($k, 'sup:'))->map(fn ($k) => (int) substr($k, 4));

        $rows = Supplier::query()
            ->where(fn ($q) => $q->whereIn('supplier_organization_id', $orgIds)->orWhereIn('id', $supIds))
            ->get(['id', 'supplier_organization_id', 'language']);

        $out = [];
        foreach ($keys as $key) {
            $mine = str_starts_with($key, 'org:')
                ? $rows->where('supplier_organization_id', (int) substr($key, 4))
                : $rows->where('id', (int) substr($key, 4));
            $out[$key] = $mine->contains('language', 'en') ? 'en' : 'ru';
        }

        return $out;
    }

    /**
     * Ответ на группу одинаковых вопросов: русский текст — русскоязычным поставщикам,
     * английский (перевод, поправленный менеджером) — англоязычным. В карточку позиции
     * QuoteParts запоминает английский вариант, если он есть.
     */
    /**
     * Закрыть вопросы без ответа: поставщику ничего не уходит, вопрос — в отвеченных, позиции снова
     * ждут цен. Для вопросов, на которые отвечать нечего («зачем вы спрашиваете у нас Montanari?»).
     *
     * @return int закрыто вопросов
     */
    public function closeQuestions(QuotePartsRfq $rfq, ?int $groupId, ?string $supplierKey, User $by): int
    {
        if (! QuotePartsPilot::canAnswer($by)) {
            throw new RuntimeException('Закрывать вопросы поставщиков могут снабжение, руководство и участники пилота.');
        }
        $snapshot = $this->client->closeQuestions($rfq->remote_id, $groupId, $supplierKey, $by->name);
        $rfq->forceFill(['snapshot' => collect($snapshot)->except('closed')->all(), 'status' => $snapshot['status'] ?? $rfq->status, 'synced_at' => now()])->save();

        return (int) ($snapshot['closed'] ?? 0);
    }

    /** @param  list<\Illuminate\Http\UploadedFile>  $files */
    public function answerGroup(QuotePartsRfq $rfq, int $groupId, string $ru, ?string $en, User $by, array $files = [], bool $withSignature = true): QuotePartsRfq
    {
        if (! QuotePartsPilot::canAnswer($by)) {
            throw new RuntimeException('Отвечать поставщикам QuoteParts могут снабжение, руководство и участники пилота.');
        }
        $group = collect($rfq->snapshot['question_groups'] ?? [])->firstWhere('id', $groupId);
        if ($group === null) {
            throw new RuntimeException('Группа вопросов не найдена — обновите запрос.');
        }
        $ru = trim($ru);
        $en = trim((string) $en);
        $askers = collect($group['questions'] ?? [])->where('status', 'open')->pluck('supplier')->filter()->unique();
        $languages = $this->supplierLanguages($askers);
        if ($ru === '' || (in_array('en', $languages, true) && $en === '')) {
            throw new RuntimeException('Напишите ответ'.(in_array('en', $languages, true) ? ' и проверьте перевод на английский' : '').'.');
        }

        $snapshot = $this->client->answerQuestionGroup(
            $rfq->remote_id,
            $groupId,
            $askers->map(fn ($key) => ['supplier' => $key, 'text' => $languages[$key] === 'en' ? $en : $ru])->values()->all(),
            $en !== '' ? $en : $ru,
            $by->name,
            ['files' => $this->uploadedFiles($files), 'signature' => $withSignature ? $this->signature($by, offsystem: ($rfq->snapshot['channel'] ?? null) === 'offsystem') : null],
        );
        $rfq->forceFill(['snapshot' => $snapshot, 'status' => $snapshot['status'] ?? $rfq->status, 'synced_at' => now()])->save();

        return $rfq;
    }

    /**
     * Черновик письма клиенту с вопросами поставщиков (по-русски, без имён поставщиков) —
     * ответом на последнее письмо клиента в заявке. Менеджер правит и отправляет сам.
     *
     * @param  list<int>  $groupIds  пусто — все открытые группы
     */
    public function clientDraft(QuotePartsRfq $rfq, array $groupIds, User $by): \App\Models\EmailMessage
    {
        $request = $rfq->request ?? throw new RuntimeException('Запрос не привязан к заявке.');
        $groups = collect($rfq->snapshot['question_groups'] ?? [])->where('status', 'open')
            ->when($groupIds !== [], fn ($c) => $c->whereIn('id', $groupIds))->values();
        if ($groups->isEmpty()) {
            throw new RuntimeException('Открытых вопросов поставщиков нет.');
        }

        $lines = $groups->map(function ($g, $i) {
            $variants = collect($g['questions'] ?? [])->map(fn ($q) => $q['text_ru'] ?: $q['text'])->filter()->unique()
                ->reject(fn ($t) => $t === $g['title_ru'])->values();

            return ($i + 1).'. '.$g['title_ru'].($variants->isNotEmpty() ? "\n   (".$variants->implode('; ').')' : '');
        });
        $body = "Здравствуйте!\n\nЧтобы подобрать позицию точно, уточните, пожалуйста:\n\n".$lines->implode("\n")."\n\nСпасибо!";

        $drafts = app(\App\Services\Mail\EmailDraftService::class);
        $lastInbound = \App\Models\EmailMessage::query()
            ->where('related_request_id', $request->id)->where('direction', \App\Enums\MailDirection::Inbound->value)->where('is_draft', false)
            ->orderByDesc('sent_at')->orderByDesc('id')->first();
        $draft = $lastInbound ? $drafts->createReply($request, $lastInbound, $by) : $drafts->createCompose($request, $by);
        $drafts->update($draft, [
            'subject' => $lastInbound ? $draft->subject : '['.$request->internal_code.'] Уточнения по заявке',
            'body_plain' => $body,
            'body_html' => nl2br(e($body)),
        ]);

        return $draft;
    }

    public function refresh(QuotePartsRfq $rfq): QuotePartsRfq
    {
        $snapshot = $this->client->rfq($rfq->remote_id);
        $rfq->forceFill([
            'snapshot' => $snapshot,
            'status' => $snapshot['status'] ?? $rfq->status,
            'reply_by' => $snapshot['reply_by'] ?? $rfq->reply_by,
            'synced_at' => now(),
        ])->save();

        return $rfq;
    }

    /**
     * Обновить снимки запросов, по которым ещё могут прийти ответы (открытые и
     * закрытые за последние 30 дней — поздние ответы письмом тоже становятся офферами).
     */
    public function refreshRecent(): int
    {
        $n = 0;
        foreach (QuotePartsRfq::query()->where('created_at', '>=', now()->subDays(60))
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('synced_at', '>=', now()->subDays(30)))
            ->orderBy('synced_at')->limit(200)->get() as $rfq) {
            try {
                $this->refresh($rfq);
                $n++;
            } catch (Throwable $e) {
                Log::warning('QuoteParts rfq refresh failed', ['rfq' => $rfq->remote_id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /**
     * Загруженные файлы ответа — в base64 для QuoteParts, в пределах того же лимита, что у запроса.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $files
     * @return list<array{name:string, mime:string, content:string}>
     */
    private function uploadedFiles(array $files): array
    {
        $out = [];
        $total = 0;
        $limit = (int) config('services.quoteparts.attachments_max_bytes', 15 * 1024 * 1024);
        foreach ($files as $f) {
            $bytes = (string) @file_get_contents($f->getRealPath());
            if ($bytes === '') {
                continue;
            }
            $total += strlen($bytes);
            if ($total > $limit) {
                throw new RuntimeException('Вложения больше '.round($limit / 1024 / 1024).' МБ — уберите часть файлов.');
            }
            $out[] = ['name' => mb_substr($f->getClientOriginalName() ?: 'file', 0, 200), 'mime' => $f->getMimeType() ?: 'application/octet-stream', 'content' => base64_encode($bytes)];
        }

        return $out;
    }

    /**
     * Письма внесистемным поставщикам по ключу поставщика в QuoteParts: тело — как у письма из mzCorp
     * (email-safe HTML) плюс подпись автора. Несколько адресов одной организации — один получатель
     * в QuoteParts: ему письмо первого из них.
     *
     * @param  array<int, array{lang:string, subject:string, html:string, plain:string}>  $letters  supplier_id => письмо
     * @param  \Illuminate\Support\Collection<int, Supplier>  $suppliers
     * @return array<string, array{subject:string, html:string, text:string, locale:string}>
     */
    private function offsystemLetters(array $letters, \Illuminate\Support\Collection $suppliers, User $by): array
    {
        $signature = $this->offsystemSignature($by);
        $safe = new \App\Services\Mail\OutboundHtmlPostProcessor;
        $out = [];
        foreach ($suppliers as $s) {
            $letter = $letters[$s->id] ?? null;
            if ($letter === null || isset($out[$s->quotepartsKey()])) {
                continue;
            }
            $out[$s->quotepartsKey()] = [
                'subject' => mb_substr($letter['subject'], 0, 500),
                'html' => $safe->emailSafe($letter['html']).(string) ($signature['html'] ?? ''),
                'text' => rtrim($letter['plain']).(($signature['plain'] ?? '') !== '' ? "\n\n".$signature['plain'] : ''),
                'locale' => $letter['lang'] === 'en' ? 'en' : 'ru',
            ];
        }
        if (count($out) !== $suppliers->map->quotepartsKey()->unique()->count()) {
            throw new RuntimeException('Не собрано письмо для части внесистемных поставщиков.');
        }

        return $out;
    }

    /**
     * Подпись автора для писем внесистемным: его личный адрес заменён ящиком канала (orders@) — поставщик,
     * пишущий «на адрес из подписи», попадает туда, где ответ прочитает QuoteParts (решение 2026-10-21).
     *
     * @return array{html: string, plain: string}
     */
    private function offsystemSignature(User $by): array
    {
        $signature = app(\App\Services\Mail\EmailSignatureService::class)->render($by);
        $channel = (string) config('services.quoteparts.offsystem_mailbox');
        // Личный ящик (если он не совпадает с логином) — из базы, только у сохранённого сотрудника.
        $personal = array_values(array_unique(array_filter([mb_strtolower((string) $by->email), $by->exists ? mb_strtolower($this->mirrorAddress($by)) : null])));
        if ($channel === '' || $personal === []) {
            return $signature;
        }
        $swap = fn (string $text) => (string) preg_replace(array_map(fn ($a) => '/'.preg_quote($a, '/').'/iu', $personal), $channel, $text);

        return ['html' => $swap((string) ($signature['html'] ?? '')), 'plain' => $swap((string) ($signature['plain'] ?? ''))];
    }

    /** Подпись автора — та же, что у писем из mzCorp, текстом (внесистемным — с адресом канала вместо личного). */
    private function signature(User $by, bool $offsystem = false): ?string
    {
        $rendered = $offsystem ? $this->offsystemSignature($by) : app(\App\Services\Mail\EmailSignatureService::class)->render($by);
        // Разделитель «-- » ставит QuoteParts — свой с начала подписи убираем.
        $plain = trim((string) preg_replace('/^\s*--\s*\R/u', '', (string) ($rendered['plain'] ?? '')));

        return $plain !== '' ? mb_substr($plain, 0, 2000) : null;
    }

    /**
     * Адреса копии: через запятую, точку с запятой или пробел, можно «Имя <адрес>».
     *
     * @return list<string>
     */
    public static function parseCc(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;\r\n]+/u', $raw) ?: [] as $part) {
            if (trim($part) === '') {
                continue;
            }
            if (preg_match_all('/[^\s<>"\']+@[^\s<>"\']+/u', $part, $m) === 0) {
                throw new RuntimeException('Не понял адрес в копии: '.mb_substr(trim($part), 0, 60));
            }
            foreach ($m[0] as $email) {
                $email = mb_strtolower(rtrim($email, '.'));
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException('Неверный адрес в копии: '.$email);
                }
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Номера в тему письма QuoteParts: код заявки и номер запроса 1С. QuoteParts принимает
     * до 32 символов из букв, цифр и «.-/_ #№» — номер 1С с мусором («365543)»,
     * «order@liftway.store») или повтор M-кода в тему не идёт, запрос из-за него не падает.
     *
     * @return list<string>
     */
    public static function subjectRefs(?string $code, ?string $onecNumber): array
    {
        $refs = [];
        foreach ([$code, $onecNumber] as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '' && mb_strlen($ref) <= 32 && preg_match('/^[\pL\pN.\-\/_ #№]+$/u', $ref) === 1 && ! in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }
}
