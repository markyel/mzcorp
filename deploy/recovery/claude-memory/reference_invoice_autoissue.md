---
name: invoice-autoissue
description: "Авто-создание Invoice из исходящих счетов: guard договора/спецификации + 'последний по номеру' (тикеты M-2026-2582, M-2026-1824)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Пайплайн авто-счёта из исходящей почты:
`OutboundDocumentDetector/Classifier` (уровень ПИСЬМА: invoice/quotation/… — задаёт СТАТУС заявки, priority invoice>quotation) → `MailRouter::dispatchOutboundQuoteParsing` диспатчит `ParseOutboundQuoteJob` для КАЖДОГО парсимого вложения (pdf/xlsx/docx) → job ставит `OutboundQuote.document_type = переданный тип` и при `outbound_invoice` зовёт `InvoiceService::autoIssueFromOutboundQuote`.

**С коммита 37c4865 (M-2026-3456) — PER-ATTACHMENT маршрутизация.** Раньше тип ПИСЬМА штамповал ВСЕ вложения. Теперь `dispatchOutboundQuoteParsing` зовёт `OutboundDocumentDetector::classifyAttachmentByFilename($name)` для КАЖДОГО вложения: «Счет/Счёт …»→invoice, «Предложение/КП …»→quotation. Файлы, что по имени НЕ самоопределяются (спецификация, doc.pdf), наследуют тип ПИСЬМА (`?? $letterType`). Зачем: одно письмо может нести И КП, И счёт; до фикса regex счёта был `счё?т` (не ловил «Счет» через е) → письмо типилось quotation, счёт штамповался КП, Invoice не создавался, заявка застревала в quoted. Regex теперь `сч[её]?т`. Парсер всё ещё извлекает number/date/amounts (не document.type) из содержимого.

Грабля-остаток: не-самоопределяющиеся файлы (договор/спецификация рядом со счётом) наследуют letterType=invoice и доходят до создания Invoice — но `looksLikeContractDocument` (см. ниже) их отсекает.

## `InvoiceService::autoIssueFromOutboundQuote` — правила
1. **Только `document_type === outbound_invoice`**, иначе null.
2. **Guard договора/спецификации** (`looksLikeContractDocument` → pure-метод `InvoiceService::isContractOrSpecFilename($name)`, тикеты M-2026-2582, M-2026-1797): пропускаем (null), если имя НЕ начинается со `счёт/счет/инвойс/invoice` (`/^(сч[ёе]т|инвойс|invoice)/u`) И содержит `договор` ИЛИ `спецификац` ИЛИ токен `спец` (`/(^|[^а-яё])спец([^а-яё]|$)/u` — ловит сокращение «Спец 31.pdf»/«Спец_31», но не «специальн…»/«спецодежда»). Т.е. «Договор … (счет 5687).pdf» / «Спец 31.pdf» → skip; «Счет МЗ-5687.pdf» → создаём. OutboundQuote сохраняется (виден в документах), не создаётся только платёжный Invoice. M-2026-1797: до фикса «Спец 31.pdf» проскакивал (ловилось только полное `спецификац`) и счёт получал номер спецификации (31) вместо счёта (5742); из-за дедупа по тому же письму настоящий счёт уже не перебивал номер.
3. **Дедуп** по `request_id` + (`email_message_id` ИЛИ `invoice_number`).
4. **Несколько счетов с одним номером → хранить ПОСЛЕДНИЙ отправленный** (тикет M-2026-1824): при найденном существующем `pending`-счёте зовём `refreshFromNewerQuote` — если источник входящего документа свежее (по `EmailMessage.sent_at`, тай-брейк по id письма; см. `isNewerSource`), обновляем сумму/дату/срок/`email_message_id`/comment, оставаясь ОДНОЙ записью. Раньше хранился ПЕРВЫЙ и корректировки терялись. Оплаченные/аннулированные/истёкшие счета не трогаем; reparse того же письма — no-op.

## Прочее
- Дедуп `->first()` без сортировки = самый ранний id (но запись одна).
- Ручная отмена ошибочного авто-счёта: `InvoiceService::cancel($invoice, $reason, $author)` (только pending; если у заявки не осталось pending — вернёт в AwaitingInvoice). Так зачистил фантомный счёт по M-2026-2582.
- Ремедиация «последнего по номеру»: прогнать версии счёта (OutboundQuote) через `autoIssueFromOutboundQuote` по возрастанию свежести — победит последний.

## Файлы / коммиты (2026-06-01)
`app/Services/Invoices/InvoiceService.php`, `app/Jobs/Quotes/ParseOutboundQuoteJob.php` (стр. ~160 document_type из типа письма, ~296 авто-issue). Коммиты: 00d902b (guard договора, M-2026-2582), c3d4316 (последний по номеру, M-2026-1824).
