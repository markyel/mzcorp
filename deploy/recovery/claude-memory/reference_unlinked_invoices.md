---
name: unlinked-invoices
description: "Раздел /dashboard/invoices/unlinked — ручной триаж исходящих счетов, не нашедших заявку (Слой B); 3-уровневые кандидаты + привязка в 1 клик"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Страница **`/dashboard/invoices/unlinked`** (`invoices.unlinked` → view `invoices.unlinked` → Livewire `App\Livewire\Invoices\Unlinked`). **Слой B** триажа потерянных счетов: сюда падают НАШИ исходящие счета, которые авто-привязка по заголовкам (`mail:relink-deferred-outbound`, Слой A) не смогла прицепить к заявке (родителя треда нет в БД). Привилегированный находит заявку и привязывает счёт вручную → запускается разбор → `Invoice`. Контекст «почему теряются» + плейбук диагностики — в [[lost-invoices-diagnosis]]; цепочка OutboundQuote→Invoice — [[invoice-autoissue]]. Вход: ссылка **«⚠ Непривязанные»** в шапке реестра `invoices.index`.

**Доступ:** privileged-only — `mount()` `abort_unless hasAnyRole([HeadOfSales, Director, Secretary, Admin])`. НЕ менеджер, НЕ procurement.

**Что показывает (`rows()` computed):** исходящие письма (`direction=outbound`, `related_request_id IS NULL`) с вложением-счётом по имени `NAME_LIKE = ['Счет МЗ-%','Счёт МЗ-%','Инвойс МЗ-%']` + regex строки `/^(Сч[её]т|Инвойс)\s+М[ЗЭ]-/u` → **только НАШИ счета** (форварды поставщицких «Счет 26-…»/«Деловые Линии Счет.pdf» отсечены). Окно по `sent_at >= now-(max(period,30)+30)д`, лимит 400 писем. **Клиент = первый ВНЕШНИЙ получатель** (`firstExternalRecipient`; письмо только на @myzip.ru = внутренняя пересылка → пропуск). Дедуп по номеру счёта; счета, у которых уже есть `Invoice` по номеру (`invoice_number` = N или «МЗ-»+N) — скрыты. Столбцы: № счёта (+filename), дата, отправитель (mailbox/from), клиент, тема+sent_at.

**Фильтр свежести** (`#[Url] days`, default 30, кнопки 7/30/90/365, `setPeriod`): **по ДАТЕ счёта из имени файла** (`parseInvoiceMeta`, fallback `sent_at`) — чтобы не захлебнуться форвардами архивных счетов («Счет МЗ-368 от 2025-01» как напоминание).

**Раскрытие счёта** «▾ Счёт» (`toggleInvoiceText`): текстовый слой PDF (**Smalot, без Vision/OpenAI**, кап 6000 симв.) — видны строки таблицы для сверки с позициями заявки; ссылка «📄 Открыть оригинал» = `attachments.preview`. У скана текст не извлечётся.

**Привязка** (`startAttach(msgId,attId)` → панель кандидатов → `attach(requestId)`), **3 уровня**:
1. 🎯 **Совпадение по M-артикулам счёта** (`articleMatchedRequests`, сильнейший сигнал) — M-коды из текста PDF (`mCodesFromText`, regex `[MМ]\s?\d{3,}`, нормализация кирилл.М→M/upper/без пробела) ∩ `request_items.catalog_item_id → catalog.sku`. Ранжир: **число совпавших артикулов ×3 + тот же клиент +1 + тот же менеджер-отправитель +1** (шаг ≥3 → мягкие сигналы не перебьют артикулы). Архивные (ClosedWon/ClosedLost) исключены, топ-8.
2. **Открытые заявки заказчика** (`clientOpenRequests`) — по email внешнего получателя, исключая показанные в (1), не-архивные, лимит 20; заявки менеджера-отправителя выше.
3. **Ручной поиск** (`requestCandidates`) — по `internal_code`/`client_email`/`client_name`/`subject`, лимит 10.

**Менеджер-отправитель** (`attachingManagerId`) = `mailboxes.owner_user_id` ящика письма (fallback `User` по `from_email`); бейдж **«👤 свой»** у кандидата, когда == `assigned_user_id` заявки (менеджер выставил счёт по своей заявке). Строка кандидата — партиал `livewire/invoices/_candidate-row.blade.php` (опц. badge артикулов + раскрытие позиций заявки eager-load).

**`attach()` в 1 клик:** гард «уже привязано» → set `related_request_id` + audit `detected_artifacts[type=manual_attach_outbound_invoice, request_id, by_user_id]` → по каждому само-определяющемуся вложению (`OutboundDocumentDetector::classifyAttachmentByFilename` ≠ null) dispatch `ParseOutboundQuoteJob(att_id, type, force=true)` → OutboundQuote → Invoice. Toast «Счёт привязан к M-NNNN, разбор запущен», сброс панели.

**Тестируемость:** `parseInvoiceMeta(filename)` (номер+дата) и `mCodesFromText(text)` — public static (unit-тест).

## Создание НОВОЙ заявки из счёта (commit d2cf8dd, 2026-06-22)
Иногда у счёта реально нет заявки → в панели привязки есть второй путь: кнопка **«➕ Создать заявку из счёта»** (под кандидатами/ручным поиском). `Unlinked::createRequest()` (privileged-only) → `App\Services\Invoices\InvoiceToRequestService::create(email, invoiceAtt, clientEmail, managerId, by)`:
- Клиент = внешний получатель (`attachingClientEmail`), менеджер = отправитель счёта (`attachingManagerId` = `mailboxes.owner_user_id`), тема из письма.
- Создаёт Request в `Assigned` (менеджер известен) / `New` → `RequestStateService::transitionTo(Invoiced)` = **«Счёт отправлен»** (прямой `Pending→Invoiced` запрещён картой переходов, поэтому через Assigned/New; `systemTransition:true`, человек в payload+artifacts). Линкует письмо (`related_request_id`) + audit `manual_create_request_from_invoice`; `RequestOrganizationResolver::attach`; `recordSystemInitial`.
- Диспатчит `ParseOutboundQuoteJob(invoiceAtt, OutboundInvoice, force)` → OutboundQuote/Invoice.
- **Посев позиций:** `ParseOutboundQuoteJob` после авто-Invoice, если у заявки **0 активных request_items**, сеет их из `outbound_quote_items` (raw_name/article/brand/quantity/unit_measure + matched_catalog_item_id, `data_source='outbound_invoice'`). Гард «0 позиций» → обычные заявки (КП→счёт) не трогаются; non-fatal. Позиции появляются ~через минуту (async-парс), как и в обычной привязке.
- Opt-in (только по клику), без миграций. Контраст: [[lost-invoices-diagnosis]] EmailToRequestPromoter создаёт из ВХОДЯЩЕГО письма клиента; здесь — из НАШЕГО исходящего счёта. Прод-проверка: dry-run на реальном «Счет МЗ-6360» (rollback) → Request status=invoiced, assigned=менеджер, клиент проставлен, осадка нет.
