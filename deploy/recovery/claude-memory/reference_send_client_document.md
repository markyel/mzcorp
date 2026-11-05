---
name: reference_send_client_document
description: Отправка клиенту КП/счёта письмом с вкладок КП/Счёт — детерминированный тип + переход статуса
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-13T12:58:55.842Z
---

Отправка клиенту КП/счёта письмом (2026-08-11). Компонент `App\Livewire\Requests\SendClientDocumentDialog` (+ blade send-client-document-dialog), зарегистрирован в detail.blade НЕ lazy (ловит событие), кнопки «✉ Отправить КП/счёт клиенту» на вкладках `@case('quotes')` / `@case('invoices')` → `$dispatch('open-send-client-document', {focus: 'quote'|'invoice'})`.

Диалог: редактируемый шаблон (свой для КП/счёта), раздельные слоты `kpFiles`/`invoiceFiles`/`extraFiles` (WithFileUploads), CC. Письмо = createReply на последнее письмо КЛИЕНТА (lastClientMessage); тема += `[internal_code] / onec_number` (buildSubject берёт тот же якорь).

**ГРАБЛЯ мультистороннего треда (fix commit 2f88d57, 2026-08-13, кейс M-2026-11901):** lastClientMessage раньше брал «последнее письмо треда любого направления» (related_request_id + supplier_inquiry_id IS NULL + не draft, order sent_at desc). Но на заявку могут висеть письма НЕСКОЛЬКИХ сторон с supplier_inquiry_id=NULL (клиент + поставщики + внутренние). Якорь съезжал на нашу переписку с поставщиком → КП уходил клиенту с ЧУЖОЙ темой, вне его цепочки (клиент <адрес>, а якорем стало наше письмо к unisystem.si «Request M-2026-11901»). In-Reply-To на чужой message_id = «новая тема» у клиента. Фикс — якорь строго по клиенту: (1) последнее ВХОДЯЩЕЕ где `lower(from_email)=client_email`; (2) иначе последнее письмо где клиент СТОРОНА (from/to/cc — jsonb_array_elements по to_recipients/cc_recipients, они jsonb); (3) фолбэк — последнее письмо треда. Уже отправленное «мимо треда» письмо не откатить — переслать заново после фикса. Отправка через EmailDraftService::createReply/update + OutgoingMailSender::sendDraft (тот же путь, что ComposeForm); вложения материализуются в EmailAttachment на черновике до send.

**КЛЮЧЕВОЕ — тип известен по слоту, НЕ гадаем детектором:** КП-файл → `DetectorType::OutboundQuotationFull` (→ статус Quoted «КП отправлено»); счёт → `OutboundInvoice` (→ Invoiced «Счёт выставлен» + авто-Invoice). Механика: `AiDecisionService::recordSuggestion(type, req, sent, 1.0)` ЗАТЕМ `apply($d, $user)` — ФОРС, т.к. shouldAutoApply зависит от `detector.auto_mode.<type>` (может быть off); apply() идемпотентен (`status->isFinal() → return`). При обоих: КП apply первым, счёт вторым → итог Invoiced (приоритет счёта). Разбор вложения — `ParseOutboundQuoteJob::dispatch(att_id, type->value, false)` по ИЗВЕСТНОМУ типу. Идемпотентно с sync-детекцией (MailRouter::runOutboundDocumentDetection на синке Sent): recordSuggestion уникален по (email_message_id, detector_type), ParseOutboundQuoteJob уникален по attachment_id → детерминированный тип побеждает (dispatched раньше). См. [[reference_outbound_quote_parsing]], [[reference_invoice_autoissue]].

Право: не секретарь + isAccessibleBy/privileged. Живой end-to-end send на 2026-08-11 не тестировался (реальное письмо клиенту).
