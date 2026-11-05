---
name: abandoned-bucket
description: Бакет пула «🕳 Заброшенные» (closed_lost + manual_other/null + peak_status NULL) — И диагностика ошибок классификации почты
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-04T17:53:53.691Z
---

**АКТУАЛЬНОЕ определение (commit 7938250, 2026-08-04) — `Pool::applyAbandonedFilter`:** «где МЫ перестали отвечать клиенту при мяче на нашей стороне». Старое (`closed_lost + peak_status IS NULL`) заказчик забраковал («не выглядят заброшенными»). Теперь 2 части через OR + активный владелец:
1. **ОТКРЫТЫЕ:** `last_activity_type = client_replied` (последнее событие = ответ клиента → мяч у нас; ТОЧНЕЕ статуса — ловит и `quoted`/`invoiced`, где клиент ответил после КП/счёта) И `last_activity_at < now - ABANDONED_SILENCE_DAYS(=5)`. Сигнал переживает простое ОТКРЫТИЕ карточки (attention-флаг client_replied снимается onManagerOpened, а `last_activity_type` — только реальным нашим ответом/сменой статуса) → ловит «посмотрел и не ответил».
2. **ЗАКРЫТЫЕ:** `closed_lost` + `manual_other`/NULL + последнее письмо треда ВХОДЯЩЕЕ (`em.id = max(em.id) AND direction=inbound`) = закрыли, не ответив клиенту.
Сортировка бакета — дольше молчим сверху (`last_activity_at ASC`). `statusesForBucket['abandoned']` расширен до суперсета (охватывает открытые+закрытые), базовый `whereIn(585)` не режет. Прод на 2026-08-04: 77 (42 откр + 35 закр). Открытая часть чистая; закрытая в осн. по делу, пара пограничных с пустым body_plain (письма-вложения/реквизиты) — можно ужесточить, если РОП увидит шум. Старый `excludeSentQuoteOrInvoice` удалён (сигнал last-email-inbound его заменил); `OutboundDocumentDetector::QUOTE_INVOICE_FILENAME_ILIKE` остался неиспользуемым.

**История (старое определение, до 7938250):** `status=closed_lost` + `manual_other`/NULL + `peak_status IS NULL` + активный владелец. Зеркалило «Наш отказ».

**ГЛАВНОЕ: бакет = золотой диагностик ошибок классификации.** LLM-разбор 175 «заброшенных» (2026-08-04): только **49 (28%) настоящих**, остальные 72% — фантомы: post_sale 47, noise 38 (пустые/спасибо-ответы), internal 15 (пересылки клиента между коллегами — внешний домен, наш InternalSenderDetector не ловит), supplier 15 (ответы поставщиков на RFQ), other 10. Разово переклассифицировано 125 фантомов → off_topic (bucket стал 50). Скрипт разбора: LLM gpt-4o батчами по 8, тип genuine/post_sale/noise/internal/supplier/other.

**Фиксы классификатора этой сессии (по паттернам фантомов):**
1. `PostSaleFulfillmentDetector::requestsInvoiceToPay` — только СВЕЖИЙ текст (cutOwnQuotedTail), «счёт» из цитаты не флипает post_sale→client_request (кейс 10660 «Отправили нам заказ?»).
2. `MailCategoryClassifier` trusted-partner блок: если `deliveryStatusInquiry` → post_sale (liftway «дата поступления по Счёт 3228» = 10799, не заявка).
3. `IncomingMailProcessor::isContentEmpty` — в ОТВЕТАХ (in_reply_to/Re:) артикул искать только в свежем ТЕЛЕ, не в унаследованной теме (спасибо-ответы на товарный тред больше не заявки).
4. `PostSaleFulfillmentDetector::deliveryStatusInquiry` — статус/отгрузка/самовывоз/возврат + reply-контекст «срок поставки/когда отгрузите» (гард от нового заказа); detect() тоже это возвращает.

**Фикс 5 (2026-08-04, кейс M-2026-2568): «выдан КП, но в бакете».** Ещё класс false-positive — КП/счёт де-факто отправлен (вложение «Предложение/Счет МЗ-…» в привязанном ИСХОДЯЩЕМ письме, `draft_author_user_id=NULL` = личный ящик), но детектор его не зарегистрировал (0 outbound_quotes, статус не дошёл до quoted, `peak_status=NULL`) → ложно «заброшено». Причина: `OutboundDocumentDetector::analyze` + догоняющий `quotes:detect-missed-outbound` ПРОПУСКАЮТ терминальные заявки (иначе КП-детект авто-реанимирует closed_lost, AiDecisionService:185) — а КП ушёл из личного ящика И заявку закрыли раньше догоняющего детекта. Таких было 5/50. Фикс (НЕ трогая статус): `Pool::excludeSentQuoteOrInvoice` — whereNotExists по исходящему письму с КП/счёт-вложением; токены имени файла канонические `OutboundDocumentDetector::QUOTE_INVOICE_FILENAME_ILIKE` (SQL-зеркало *_FILENAME_RE), применён в render+счётчике. Связано: [[outbound-kp-race]], [[lost-invoices-diagnosis]].

**НЕ решено (нужен другой рычаг, не лексика):** post_sale-реплаи семантически разнородны (лексикой ловит 3/47) — главный источник фантомов это **реплаи, не привязавшиеся к своему треду → новые заявки**. Рекомендация: усиление `InboundReplyLinker` (тред-линковка) + промпт-правило «ответ про существующий заказ/счёт без новых позиций = не новая заявка». Riskier, отдельной сессией. Связано: [[postsale-routing]], [[cited-quote-invoice-routing]], [[duplicated-source-of-truth]].
