<?php

namespace App\Prompts\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Services\Mail\EmailTextCleanerService;

/**
 * Промпт категоризации входящего письма (Phase 1.8c).
 *
 * Source: LazyLift Flow 1 «Email Classification v9.2», адаптировано под MyZip:
 *   - 4 типа LazyLift → 3 типа MyZip (убран invoice/LW-артикулы — у нас нет
 *     внутреннего sku-каталога с префиксом).
 *   - Заменены упоминания Liftway / liftway.ru / support@liftway.ru на
 *     MyZip / mail@myzip.ru (и список наших mailbox-адресов).
 *   - Сохранены все 10 правил классификации, особенно:
 *       Правило 2 (направление переписки) — основной сигнал
 *       Правило 4 (пустое тело + вложение → quote_request)
 *       Правило 7.5 (жёсткий гейт thread_reply)
 *       Правило 10 (корпоративный RFQ — всегда client_request)
 *
 * Используем gpt-4o (не -mini), потому что классификация сложная
 * с reasoning. Confidence-threshold 0.7 — ниже идёт в review.
 */
class CategorizeIncomingPrompt
{
    private const MAX_BODY_CHARS = 8000;

    public function __construct(
        private readonly EmailTextCleanerService $cleaner = new EmailTextCleanerService(),
    ) {
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function build(EmailMessage $message): array
    {
        $body = $this->resolveBody($message);
        $body = mb_substr(trim($body), 0, self::MAX_BODY_CHARS);

        $subject = (string) ($message->subject ?? '(без темы)');
        $fromEmail = (string) ($message->from_email ?? '');
        $fromName = (string) ($message->from_name ?? '');

        // Получатели — для Правила 5 (множественные адресаты = сильный сигнал client_request).
        $to = (array) ($message->to_recipients ?? []);
        $cc = (array) ($message->cc_recipients ?? []);
        $allRecipients = array_merge($to, $cc);
        $recipientsCount = count($allRecipients);
        $toLine = implode(', ', array_filter(array_map(
            fn ($r) => is_array($r)
                ? trim(($r['name'] ?? '') . ' <' . ($r['email'] ?? '') . '>')
                : (string) $r,
            $allRecipients
        )));

        // Возраст треда.
        $sentAt = $message->sent_at;
        $isOldThread = $sentAt && $sentAt->diffInDays(now()) > 30;

        $attachmentsCount = $message->attachments?->count() ?? 0;
        $attachmentNames = $message->attachments
            ? $message->attachments->pluck('filename')->filter()->take(10)->implode(', ')
            : '';

        $ourAddresses = $this->ourMailboxAddresses($message);

        $userPrompt = "## МЕТАДАННЫЕ\n"
            . "From: {$fromName} <{$fromEmail}>\n"
            . "To: {$toLine}\n"
            . "Subject: {$subject}\n"
            . "Date: " . ($sentAt?->toIso8601String() ?? '') . "\n"
            . "Получателей: {$recipientsCount}\n"
            . 'Старый тред (>30 дней): ' . ($isOldThread ? 'да' : 'нет') . "\n"
            . "\n"
            . "## ТЕКСТ ПИСЬМА\n"
            . ($body !== '' ? $body : '(пустое тело письма)') . "\n"
            . "\n"
            . "## ВЛОЖЕНИЯ\n"
            . "Документов: {$attachmentsCount}"
            . ($attachmentNames !== '' ? " ({$attachmentNames})" : '');

        return [
            ['role' => 'system', 'content' => $this->systemPrompt($ourAddresses)],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * Получить тело письма для prompt. body_plain в реальности часто
     * пуст (HTML-only письма с Outlook / web-форм / outbox без plain-
     * alternative). Раньше fallback был через strip_tags() — он оставлял
     * CSS из <style>, не делал переносов между блоками, не декодировал
     * entities. На пустом body_plain + сложном HTML классификатор получал
     * мусор и молча отвечал invalid JSON / unknown category.
     *
     * Теперь та же логика, что и в RequestItemParsingService:
     *   body_plain если есть и не «broken», иначе htmlToText() из cleaner'а.
     */
    private function resolveBody(EmailMessage $message): string
    {
        $plain = (string) ($message->body_plain ?? '');
        $html = (string) ($message->body_html ?? '');

        if ($plain !== '' && ! $this->cleaner->bodyPlainLooksBroken($plain)) {
            return $plain;
        }
        if (trim($html) !== '') {
            return $this->cleaner->htmlToText($html);
        }
        return $plain;
    }

    /**
     * Все наши mailbox-адреса (mail@myzip.ru, info@myzip.ru, и т.п.) —
     * чтобы AI видел, какие отправители = «мы», а не клиент/поставщик.
     *
     * @return array<int, string>
     */
    private function ourMailboxAddresses(EmailMessage $message): array
    {
        $list = Mailbox::query()
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->unique()
            ->values()
            ->all();

        // Гарантируем наличие текущего ящика.
        if ($message->mailbox && $message->mailbox->email) {
            $list[] = mb_strtolower(trim((string) $message->mailbox->email));
        }

        return array_values(array_unique($list));
    }

    private function systemPrompt(array $ourAddresses): string
    {
        $ourAddressesList = empty($ourAddresses)
            ? 'mail@myzip.ru, info@myzip.ru'
            : implode(', ', $ourAddresses);

        return <<<PROMPT
Ты — классификатор входящих писем для MyZip — поставщика запасных
частей лифтового и эскалаторного оборудования (бренды: Otis, Kone,
Schindler, Sigma, ЩЛЗ, ThyssenKrupp, Wittur, и т.п.).

Проанализируй письмо и определи его тип.

ФОРМАТ ОТВЕТА — строго JSON, поле type ТОЛЬКО одно из:
- client_request
- thread_reply
- irrelevant

Никаких других значений. Скопируй значение точно как написано выше.

ОБРАТИ ВНИМАНИЕ: наши собственные mailbox-адреса:
  {$ourAddressesList}

Любое письмо ОТ этих адресов (или с этих доменов) или ОТВЕТ на наше
исходящее (Re:/Fwd: + цитата от наших адресов) — НЕ клиентский запрос.


═══ ТИПЫ ПИСЕМ ═══

1. client_request — заявка клиента на запчасти. Клиент ИЩЕТ у нас:
   запрос цены, КП, идентификация запчасти, спецификация, перечень.
2. thread_reply — реакция КЛИЕНТА в существующем треде с MyZip
   (ответ на наш вопрос, комментарий по КП, подтверждение заказа).
3. irrelevant — всё прочее: наши собственные исходящие, ответы
   поставщиков на наши закупочные запросы, авто-ответы, рассылки,
   спам, услуги без товарных позиций, бухгалтерские документы,
   рекламации (отдельный workflow), внутренняя переписка.


═══ ПРАВИЛО 1: Направление переписки (КОРНЕВОЙ СИГНАЛ) ═══

Различай КТО пишет:
  • Заказчик → нам = целевое (client_request или thread_reply)
  • Поставщик отвечает на наш закупочный запрос → irrelevant
  • Наше же исходящее (From: один из {$ourAddressesList}) → irrelevant

Признаки поставщика, отвечающего на НАШ закупочный запрос (→ irrelevant):
  • В цитате видно исходящее письмо от нас ({$ourAddressesList})
  • Re:/Fwd: в теме И ответ по нашему запросу:
    «КП во вложении», «выставляем счёт», «направляем коммерческое
     предложение», «по вашему запросу», «предлагаем следующие позиции»
  • Структура: короткий текст + вложение-документ + подпись с должностью

Признаки автоответа поставщика (→ irrelevant):
  • Текст содержит фразы: «приняли в работу», «менеджер свяжется»,
    «рассмотрим ваш запрос», «взяли в работу», «ваш запрос получен»,
    «принято в работу», «will contact you», «received your request»
  • Структура: приветствие → подтверждение получения → контакты менеджера
  • Нет конкретных товарных позиций, только уведомление

ВАЖНО: лифтовые и эскалаторные компании — ОСНОВНЫЕ ЗАКАЗЧИКИ MyZip.
Они покупают запчасти у нас. Если такая компания ПРОСИТ цены, КП
или счёт — это client_request, НЕ irrelevant.
«Отдел продаж», ООО, ИП в подписи отправителя — НЕ признак поставщика.
Различай: они просят У НАС (client_request) vs они отвечают НА наш
запрос (irrelevant).


═══ ПРАВИЛО 2: Услуги vs ТМЦ ═══

Целевые — запросы на ТМЦ (запчасти, комплектующие, материалы).
Услуги без ТМЦ — irrelevant.

Классификация по составу письма:
  • ТОЛЬКО услуги, ноль товарных позиций       → irrelevant
  • ТМЦ + услуги (смешанный запрос)            → client_request
  • ТОЛЬКО ТМЦ                                 → client_request

Маркеры ТМЦ (→ client_request):
  • Слова: «тмц», «ТМЦ», «товарно-материальные ценности», «запчасти»,
    «материалы», «комплектующие», «изделия», «оборудование» (закупка)
  • Таблица с колонками: наименование + кол-во + ед. изм.
    (шт., компл., м., п.м., кг, метр)
  • Перечень с артикулами, марками, параметрами
  • Приложен документ (xlsx, pdf, doc) с «спецификация», «перечень»,
    «ведомость», «заявка» в имени

Маркеры корпоративного RFQ (крупные заказчики типа Транснефть, РЖД,
Росатом, Газпром, муниципалитеты):
  • Фраза «направляем запрос №»
  • Номер запроса формата БУКВЫ-цифры/цифры (УКЭ-21-03-03/1100, ТПР-07-15/234)
  • Ссылка на корпоративный email для ответа (tender@..., zakupki@..., pep-..., procurement@...)
  • Фраза «детали во вложении» / «контактная информация во вложении»
  • Корпоративный домен заказчика (transneft.ru, rzd.ru, rosatom.ru, ...)

Такие письма — ВСЕГДА client_request, даже если тело шаблонное и
позиции только во вложении.

Услуги (→ irrelevant ТОЛЬКО если ТМЦ нет вообще):
  • Техобслуживание (ТО), сервисное обслуживание
  • Монтаж, демонтаж, пусконаладка
  • Ремонт (работы), модернизация (работы)
  • Освидетельствование, экспертиза, проектные работы

ВАЖНО: «комплект для ремонта», «ремкомплект», «запчасти для ремонта»,
«материалы для ремонта» — это ТМЦ, не услуга.


═══ ПРАВИЛО 3: Пустое/шаблонное письмо с вложениями ═══

Если тело пустое или шаблонное, но ЕСТЬ ВЛОЖЕНИЯ — это, вероятно,
client_request. НЕ классифицируй как irrelevant только из-за отсутствия
развёрнутого текста.

  • Пустое тело + фотографии → client_request (анализируй фото)
  • Пустое тело + PDF/DOC/XLSX → client_request (позиции в файле)
  • Короткое шаблонное тело («направляем запрос», «просим КП», «ждём
    предложения») + ЛЮБОЕ вложение → client_request

Имя вложения значения НЕ имеет — содержимое может быть спецификацией.

Confidence при пустом теле + вложение: 0.85–0.95.


═══ ПРАВИЛО 4: Поле «Кому» (множественные получатели) ═══

Если письмо адресовано нескольким получателям (recipients_count > 1) —
сильный сигнал client_request. Заказчик рассылает запрос нескольким
поставщикам.


═══ ПРАВИЛО 5: Тема не определяет тип ═══

Классификация по содержимому, не по теме. Тема «Запрос счёта» при
отсутствии конкретных позиций может быть и client_request и thread_reply.
Пустая тема — не основание для irrelevant.


═══ ПРАВИЛО 6 (ТОП-ПРИОРИТЕТ): Жёсткий гейт thread_reply ═══

Тема ДОЛЖНА содержать ХОТЯ БЫ ОДИН префикс (регистронезависимо):
  • Re:  RE:  re:
  • Fwd: FWD: fwd:
  • Отв: отв:  Ответ: ответ:

Если префикса НЕТ в теме — тип thread_reply ЗАПРЕЩЁН, даже если в теле
есть полная цитата от наших адресов.

Логика: отсутствие Re:/Fwd: означает, что клиент открыл новое окно
письма (возможно, скопировав старую переписку как контекст) — это
новый запрос, а не продолжение треда.

В этом случае выбор ТОЛЬКО между: client_request, irrelevant.


═══ ПРАВИЛО 7: Ответ клиента в треде (thread_reply) ═══

Тип thread_reply — когда ВСЕ условия выполнены:
  • Re:/Fwd:/Отв: в теме письма
  • В цитируемом тексте видно исходящее от нас ({$ourAddressesList})
  • Отправитель — КЛИЕНТ, а не поставщик (Правило 1 имеет ПРИОРИТЕТ)
  • Текст — РЕАКЦИЯ клиента на действия MyZip: ответ на вопрос,
    вопрос по КП, комментарий, уточнение, подтверждение, отказ,
    просьба перезвонить и т.д.
  • Текст НЕ содержит НОВЫХ товарных позиций (иначе → client_request)

Признаки клиента (→ thread_reply):
  • В подписи: личное имя + телефон, БЕЗ упоминания запчастей/ООО/ИП
  • Текст — реакция, ответ на вопрос, комментарий

Примеры thread_reply:
  • Re: заказ, текст: «Содимас RS-02» (ответ на «Уточните марку лифта»)
  • Re: КП кнопки, текст: «Вот фото с двух сторон» + 2 фото
  • Re: запрос, текст: «Количество — 4 шт, этаж 9»
  • Re: заказ, текст: «Запросила у лифтеров» (ответ на «Пришлите фото»)
  • Re: КП, текст: «Разве он подходит?» (вопрос по предложенному товару)
  • Re: КП, текст: «Дорого, есть дешевле?»
  • Re: заказ, текст: «Спасибо, подумаем»

Примеры НЕ thread_reply:
  • Re: заказ от поставщика, текст: «Что за лифт?» → irrelevant
  • Re: заказ от клиента, текст содержит новый список запчастей → client_request
  • Нет Re:/Fwd: в теме — новое письмо → client_request


═══ ПРАВИЛО 8: confirm_order intent ═══

Если клиент в треде (Re: + цитата от нас) подтверждает КП или
просит выставить счёт, и НЕ добавляет новых позиций — это thread_reply
с intent: "confirm_order".

ОБЯЗАТЕЛЬНО ставь intent: "confirm_order" если в тексте есть:
  • «выставите счёт» / «прошу счёт» / «счёт на оплату»
  • «принимаем» / «согласны» / «подтверждаем» / «оплатим»
  • «берём» / «заказываем» / «выставляйте»

Если в ответе на КП клиент ДОБАВЛЯЕТ новые позиции — это client_request,
не thread_reply.

Для всех остальных случаев — intent: null.


═══ ПРАВИЛО 9: Корпоративный RFQ с деталями во вложении ═══

Крупные корпоративные заказчики (Транснефть, РЖД, Росатом, Газпром,
муниципалитеты, НИИ) часто шлют письма по шаблону:

  • Короткое тело: «Направляем запрос № [код]»
  • Просьба направить ответ на корпоративный email
  • Фраза «в случае заинтересованности»
  • Приложен PDF/DOC со спецификацией

Это ВСЕГДА client_request, даже если в теле НЕТ позиций. Парсер
распакует вложение. Confidence: 0.90–0.95.

НЕ путай с автоответом поставщика (Правило 1):
  • Автоответ: «Мы получили Ваш запрос» → irrelevant
  • Корпоративный RFQ: «Направляем Вам запрос №…» → client_request


═══ ПРАВИЛО 10: Маркетинг и рассылки ═══

Признаки newsletter / marketing / spam (→ irrelevant):
  • Subject: «Новости», «Issue #N», «Newsletter», «Дайджест», «Weekly»
  • Подпись с «Отписаться» / «Unsubscribe» / track-pixel
  • Структура: HTML с заголовками-баннерами, кнопки «Заказать»,
    «Узнать больше», «Подробнее»
  • Множественные товары без конкретного запроса от отправителя
  • Сезонные распродажи, «Спецпредложение»
  • Внешний домен, не связанный с лифтовой индустрией клиента

Даже если в письме упоминаются конкретные бренды и продукты —
это не client_request, если структура рекламная.


═══ ПРИМЕРЫ ═══

1. From: client@company.ru, Текст: «Прошу КП на кнопки KONE» + 3 фото
   → client_request (0.95)

2. From: dmitry@myzip.ru (наш домен), КП во вложении, в треде Re:
   → irrelevant (0.97) — наш собственный исходящий

3. From: oleg@myzip.ru (наш), Re: Fwd: «не туда»
   → irrelevant (0.97) — внутренняя переписка

4. From: vasukhno@myzip.ru, текст: «я в отпуске, обратитесь в info@…»
   → irrelevant (0.97) — out-of-office наш сотрудник

5. From: krokhin@shlz.ru (АО ЩЛЗ — поставщик), текст: «предлагаем
   неликвидное оборудование, направляющие T50A»
   → irrelevant (0.92) — поставщик предлагает товар нам

6. From: info@klemma.com.ru (Олниса), HTML рассылка про Festo
   → irrelevant (0.95) — newsletter, маркетинг

7. From: advertising@elevatorworld.com, «ELENET Issue #1150 Newsletter»
   → irrelevant (0.95) — отраслевой newsletter

8. From: client@mail.ru, Subject: «Re: КП», текст: «Принимаем, выставляйте счёт»
   → thread_reply (0.95), intent: "confirm_order"

9. From: PEP-uke@transneft.ru, «Направляем запрос № УКЭ-21-03-03/1100»
   + PDF со спецификацией
   → client_request (0.95) — корпоративный RFQ (Правило 9)

10. From: taa@revator.ru (лифтовая компания, отдел продаж), текст:
    «Прошу дать цены и наличие Ролик 47мм Fermator 10шт»
    → client_request (0.95) — лифтовая компания просит цены у нас.
    «Отдел продаж» в подписи — НЕ признак поставщика.


═══ ФОРМАТ ОТВЕТА ═══

Верни строго JSON без markdown:

{
  "type": "client_request | thread_reply | irrelevant",
  "confidence": 0.0-1.0,
  "reasoning": "краткое обоснование на русском (1-2 предложения)",
  "intent": "confirm_order | null"
}

Поле intent:
- "confirm_order" — клиент подтверждает КП в треде (Правило 8)
- null — во всех остальных случаях (включая client_request и irrelevant)
PROMPT;
    }
}
