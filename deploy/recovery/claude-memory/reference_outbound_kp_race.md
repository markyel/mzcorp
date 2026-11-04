---
name: outbound-kp-race
description: Гонка «КП ушёл из почтового клиента раньше создания заявки» → детектор КП пропускал письмо; self-heal команда quotes:detect-missed-outbound + крон
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-04T12:43:08.813Z
---

Симптом: заявка висит в «Назначена»/«В работе», хотя КП клиенту отправлен (кейс req 6324 / M-2026-6323).

**Корень (гонка по времени):** менеджер отвечает КП напрямую из почтового клиента; его копия из папки Sent синкается в CRM на 1-2 сек РАНЬШЕ, чем приходит входящее, создающее заявку. Детектор исходящих документов (`MailRouter::route`) гейтится на `related_request_id != null` (см. [MailRouter.php:~94](app/Services/Mail/MailRouter.php)). В момент синка заявки ещё нет → письмо пропущено. Reply-linker потом проставляет `related_request_id`, но детектор повторно НЕ запускается → КП не распознан (нет `ai_decision`/`OutboundQuote`), статус занижен. Бэкфилл 2026-06-30 нашёл 99 заявок (в т.ч. ~24 открытых/недавних).

**Фикс (commit 75955da):**
- Блок детекта вынесен из `MailRouter::route` в публичный `MailRouter::runOutboundDocumentDetection(EmailMessage, Request)` (поведение синка не изменилось — он теперь вызывает этот же метод). Внутри: rule-based → LLM fallback → `AiDecisionService::recordSuggestion` (АВТО-применяет переход в Quoted/Invoiced при confidence ≥ `detector.confidence_threshold`=0.85) → `dispatchOutboundQuoteParsing` (async `ParseOutboundQuoteJob` → OutboundQuote/Invoice).
- Команда `quotes:detect-missed-outbound {--apply}{--days=14}{--limit=200}{--include-terminal}{--request=CODE}` (`QuotesDetectMissedOutboundCommand`): берёт исходящие, привязанные к НЕтерминальной заявке, с КП/счёт-вложением (по `classifyAttachmentByFilename`, дёшево без LLM), у которых нет ни одного `ai_decision`, и прогоняет `runOutboundDocumentDetection`. Идемпотентно (после прогона есть ai_decision → не eligible). Dry-run по умолчанию.
- Крон: `quotes:detect-missed-outbound --apply --days=3` hourly (self-heal, как [[lost-invoices-diagnosis]] quotes:reparse-failed / mail:relink-deferred-outbound).

Бэкфилл прода (--days=60): 24 обработано, 16 сменили статус (assigned/in_progress→quoted, awaiting_invoice→invoiced для счетов), остальным до-зарегистрирован КП/Invoice. Файлы наших КП/счетов: «Предложение МЗ-…», «Счет МЗ-…». Связано: [[outbound-quote-parsing]], [[invoice-autoissue]]. БД только на проде ([[prod-deploy]]).

## Send-time детект из композера «Переписка» (commit b5f0a68, 2026-07-14)
Тот же класс пропуска, но другой триггер: счёт/КП, приложенные ФАЙЛОМ через вкладку «Переписка» карточки заявки, меняли статус с задержкой **до часа**. Кейс M-2026-8222 (id 8223): менеджер отправил «Счет МЗ-7323.pdf» в 13:08, статус `in_progress→invoiced` сменился только в 14:00 (`event: ai_auto_apply`) — почасовым кроном `quotes:detect-missed-outbound`.

**Корень:** `ComposeForm::send()` (композер) СОЗДАЁТ запись исходящего письма сам (row с `related_request_id`+`folder=Sent`+attachments, created_at за секунды ДО sent_at). Когда `mail:sync` (каждые 2 мин) видит эту же копию в Sent, он дедупит по Message-ID и **пропускает** `runOutboundDocumentDetection` (детект в `MailRouter` бежит только для НОВЫХ синканных писем). Итог — быстрый 2-мин путь по письмам из композера не отрабатывает; статус двигает только hourly self-heal.

**Фикс (`ComposeForm.php`):** после успешного `sendDraft` + `applyPostSendHooks` вызываем новый `detectOutboundDocuments($sent)` → `app(MailRouter::class)->runOutboundDocumentDetection($sent, $request)` — СРАЗУ. Гарды: только если письмо НЕ прошло через внутренний builder (`applyPostSendHooks` теперь возвращает bool — обработал ли marker `quotation_sent`/`clarification_batch`; КП/уточнения из builder'а сами двигают статус, детект не дублируем), только с вложениями, привязано к заявке. Обёрнуто в try/catch (плюс внутренний в самом методе) — сбой детекта не ломает уже прошедшую отправку/редирект. Идемпотентно с синком/кроном (recordSuggestion дедупит по email_message_id+type, ParseOutboundQuoteJob по attachment_id).

## КП на Pending → запрещённый переход (commit 468c4f7, 2026-08-04)
Другой корень того же симптома «КП отправлен, а статус занижен»: КП РАСПОЗНАН (есть `outbound_quote` + `ai_decision` conf 0.9), но `ai_decision` **dismissed** с `dismiss_reason` = «Запрещённый переход: **pending → quoted**». Кейс M-2026-6195: КП ушёл, пока заявка ещё висела в `Pending` (не разведена роутингом; висела там ~месяц до assign). Escape-hatch 2026-05-22 расширил на прямой →Quoted/Invoiced только `New`, а `Pending → [New, Assigned, ClosedLost]` — нет. Скан 164 dismissed outbound-doc: `pending→quoted/invoiced` (31) = реальный пробел; `awaiting_invoice/invoiced/under_review→quoted` (130) = КОРРЕКТНЫЕ отказы отката назад (не трогать).

**Фикс:** `AiDecisionService::apply` — блок `shouldPromotePending` (зеркало `shouldReanimateOutbound` для closed_lost): если статус `Pending` и тип outbound-документ → сперва `transitionTo(Assigned)` (owner уже есть), затем штатно Assigned→Quoted/Invoiced. Нет назначенца → переход падает, ловим, остаёмся dismissed (без регрессии — заявку без владельца в Quoted не выводим). Cron `quotes:detect-missed-outbound` эти НЕ ловит (гейт `whereNotExists ai_decision`, а тут decision есть=dismissed); `recordSuggestion` дедупит только по `Suggested`, поэтому повторный `runOutboundDocumentDetection` создаёт НОВОЕ решение и авто-применяет. Исцелено вручную 2 живых кейса (6195→quoted, 1892→invoiced).

## Номер 1С в теме RFQ поставщику (commit 1bc44ae, 2026-07-14)
Тема запроса расценки поставщику несёт и код заявки MZC, и номер 1С при наличии: `Запрос расценки — [M-2026-XXXX] / [360989]` (EN: `Price request — …`). Через `filled($request->onec_number)` в `SupplierDispatchService::dispatch` И в превью `SupplierDispatchPanel` (держать в синхроне!). Пусто → только код. См. [[suppliers-module]], [[onec-number]].
