---
name: lost-invoices-diagnosis
description: "Жалоба «отправленный счёт не виден в /dashboard/invoices»: 2 причины (OpenAI quota-fail парсера + непривязка письма) + плейбук диагностики"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Исходящий счёт становится `Invoice` (видимым в `/dashboard/invoices`) только если пройдёт ВСЯ цепочка: письмо засинкано как outbound → `OutgoingMailLinker` привязал его к заявке (`related_request_id`) → `OutboundDocumentDetector` распознал счёт → `ParseOutboundQuoteJob` распарсил и извлёк `document_number` → `InvoiceService::autoIssueFromOutboundQuote` создал `Invoice`. Любой обрыв = счёт молча теряется. См. [[invoice-autoissue]].

## Две причины потери (кейс 17.06.2026, счета 6190/6197)

**1. Парсер упал на OpenAI `insufficient_quota` (429).** 15.06.2026 кончилась квота OpenAI на ~3 часа (11:58–15:00). `ParseOutboundQuoteJob` (tries=2) оставлял `OutboundQuote.status=failed` НАВСЕГДА — авто-ретрая не было. ~8 счетов + 23 КП застряли. Заявка при этом могла уйти в статус `invoiced` (переход применяет AiDecision отдельно от создания Invoice-строки) — отсюда парадокс «заявка invoiced, а счёта в разделе нет». **Фикс:** новый self-healing крон `quotes:reparse-failed` (каждые 30 мин, PR `fix/lost-outbound-invoices-reparse`) переразбирает failed-документы когда квота вернулась; + `ParseOutboundQuoteJob` теперь кормит `OpenAiCircuitBreaker` (раньше его кормил только `MailCategoryClassifier`) → quota-сбой при разборе счёта алертит админа.

**2. Письмо не привязалось к заявке** (`email_messages.related_request_id = NULL`). Тогда детектор счёта вообще не запускается (`MailRouter::route` гейт `if ($linkedRequest !== null)`), `OutboundQuote` не создаётся. **Точная причина (кейс 6197, разобран 17.06):** ГОНКА ОТЛОЖЕННОЙ ПРИВЯЗКИ, а не «неопределимая заявка». Счёт 6197 ушёл 15.06 14:29 (`In-Reply-To` → msg#20839), а заявка `M-2026-4445` и привязка родителя msg#20839 появились в 15:10 — на момент обработки счёта `OutgoingMailLinker` L1 не нашёл привязанного родителя → ушёл в L4 → отказ (у клиента 20+ заявок). Для ВХОДЯЩИХ эту гонку лечит крон `mail:relink-deferred`, а для ИСХОДЯЩИХ аналога НЕТ → счёт завис навсегда. **Решено (Piece 2, 17.06, ветка merged в main — деплой через route:cache+view:clear+npm):**
- **Слой A — авто-relink исходящих:** `OutgoingMailLinker::tryLink($msg, $allowFuzzyRecipientMatch=true)` получил флаг отключения L4. Команда `mail:relink-deferred-outbound` (крон каждые 15 мин, `--apply`) перепривязывает зависшие исходящие со счётом/КП ТОЛЬКО по заголовкам/коду (L4 off) и дёргает `ParseOutboundQuoteJob` → Invoice. Гард `--fresh-days=30`: разбираем только документы с датой ≤30д (иначе воскрешает пересылки архива). Первый прогон вживую: перепривязал 9 док-в, создал счета 6193/6196/6207.
- **Слой B — ручной триаж:** `/dashboard/invoices/unlinked` (Livewire `App\Livewire\Invoices\Unlinked`, privileged-only роль head_of_sales/secretary/director/admin) — список СВЕЖИХ непривязанных НАШИХ счетов («Счет МЗ-NNNN», внешний получатель; старьё/поставщики/внутр-пересылки отфильтрованы), по кнопке «Привязать» показывает кандидатов 3 уровнями: **(1) 🎯 совпадение по M-артикулам** — M-коды из текста PDF счёта (Smalot, `mCodesFromText`) ∩ `request_items.catalog_item_id→catalog.sku`, ранжир композитный: число совпавших артикулов ×3 + тот же клиент +1 + тот же менеджер-отправитель +1 (computed `articleMatchedRequests`; эмпирика: счёт 1С несёт наши M-SKU, совпало 4/5 на тесте). Менеджер-отправитель = `mailboxes.owner_user_id` ящика письма (`attachingManagerId`), бейдж «👤 свой» если == `assigned_user_id` заявки; кейс 6289 совпал по всем трём сигналам; **(2)** открытые заявки заказчика (по email-получателю, `clientOpenRequests`, исключая уже показанные); **(3)** ручной поиск по коду/клиенту/теме. Привязка в 1 клик (set related_request_id + dispatch разбор). У каждого кандидата раскрытие позиций (Alpine, request_items eager-load), у счёта — раскрытие текста PDF + ссылка `attachments.preview`. Строка-кандидат — partial `_candidate-row.blade.php` (опц. `$badge` совпавших артикулов). Ссылка «⚠ Непривязанные» в шапке реестра. `Unlinked::parseInvoiceMeta` (номер+дата из имени) — public static + unit-тест.

Ручной фикс одиночного письма (если надо точечно): `app(OutgoingMailLinker::class)->tryLink($msg)` → `ParseOutboundQuoteJob(force)`. Так заведён 6197 → Invoice #775 (req 4445).

## Плейбук диагностики (всё на ПРОДЕ, БД только там — ssh root@<IP>, см. [[prod-deploy]])
- `Invoice::where('invoice_number','ilike','%NNNN%')` — есть ли вообще.
- `EmailAttachment::where('filename','ilike','%NNNN%')` → у `emailMessage` смотри `direction`, `related_request_id` (NULL = причина №2), `mailbox_id`, `folder`.
- `OutboundQuote::whereIn('email_attachment_id',[...])` → `status=failed` + `parse_error` с `insufficient_quota`/`429` = причина №1.
- Ручной переразбор: `quotes:reparse-failed --apply` (или точечно `quotes:parse-outbound --apply --reset --quote=<id>`). Проверь, что квота жива (мини-chat к gpt-4o-mini) перед прогоном.

## Идея на будущее: счета можно парсить БЕЗ Vision (отложено заказчиком 17.06)
Замер на проде: текст-слой PDF (Smalot) даёт **номер счёта + сумму со 100% точностью** (76/76 совпали с document_number/total_amount) — метки чистые («Счет на оплату № … от …», «на сумму X»). Счёту для Invoice нужны только №+сумма → счета можно парсить текстом, **полностью убрав зависимость от Vision/квоты** (источник этой самой проблемы). КП так не выйдет: позиции текстом достаются только ~50-80% (числа/токены в таблице слипаются, Vision точнее) — КП оставляем на Vision. Реализация: текст-парсер как primary для outbound_invoice в ParseOutboundQuoteJob, Vision — фолбэк. Заказчик пока отложил («пока не надо»).

## Грабли переразбора пересланных старых счетов
Переразбор `failed`-батча создаёт pending-Invoice и для ПЕРЕСЛАННЫХ старых счетов (менеджер форвардит «Счет 5748» в тред другой заявки) — получается лишний pending на чужой заявке (5748/5820/6192 после ремедиации 17.06). Не дубль на одной заявке, но шум. Также edge-case: несколько счетов в ОДНОМ письме схлопываются дедупом по `email_message_id` в один Invoice (5106 потерялся рядом с 5748).
