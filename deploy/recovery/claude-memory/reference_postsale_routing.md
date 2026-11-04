---
name: postsale-routing
description: Как MyLift решает post_sale vs новая заявка для входящей почты; правила привязки/НЕ-создания заявок (тикеты M-2026-2706/2762/1534)
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-07-31T08:07:37.405Z
---

Маршрутизация входящего письма: `MailCategoryClassifier` (категория) → `InboundReplyLinker` (привязка к существующей заявке) → `MailRouter` (создание заявки). Заявка создаётся СТРОГО по `EmailCategory`: создают только `client_request` и `thread_reply`; `post_sale` и `irrelevant` — НЕ создают (см. `IncomingMailProcessor::processIfRequest` accepted-list).

## post_sale (постпродажа по уже оформленному заказу)
- **Привязка к заказу — ТОЛЬКО при надёжном совпадении** линкера по заголовкам/коду (In-Reply-To / References / subject-code `M-2026-NNNN`) И если статус заказа в наборе «оплачен/закрыт»: `awaiting_invoice / invoiced / paid / closed_won`. Тогда `MailRouter::handlePostSaleMessage` (attach + алерт менеджеру), заявка НЕ создаётся.
- **Нечёткий поиск «последнего оплаченного заказа по from_email» УДАЛЁН** (был временно в 33802f4, убран в 0271250 — угадывал не тот заказ, тикет M-2026-2762; и приклеил чужое письмо к M-2026-1534). `MailRouter::resolvePostSaleRequest(?Request $linkedRequest)` теперь принимает только linkedRequest.
- **Нет надёжной привязки → письмо остаётся в info@ нетронутым, без заявки.** Угадывать заказ нельзя (в архиве могут быть платёжки по разным счетам).

## Детерминированный детектор post_sale (по контенту, без вложений/заказа)
`app/Services/Mail/PostSaleFulfillmentDetector.php` — pre-LLM short-circuit в `MailCategoryClassifier` (рядом с InternalSender/TrustedPartner/UnintendedRecipient). gpt-4o упорно ставил `client_request` на терсовые «Прошу поставить на комплектацию» (тикеты M-2026-2706/2762), промпт не лечил. Форсит `post_sale` если: тема содержит `отгрузк…` ИЛИ тело `на комплектаци…`/`отгруз…`, И НЕТ маркеров нового запроса (`посчита/прайс/стоимост/цена/ цена/шт/КП…`). Заказ НЕ ищет, письмо ни к чему не привязывает. Форс касается ОБОИХ методов: `detect()` (отгрузка) и `detectDocsOrFulfillment()` (+ DOCS_STEMS: реквизиты/УПД/счёт-фактура/акт сверки/товарн — но эти только в контексте закрытого треда с оплаченным заказом, не в общем inbox).

### Гард «запрос счёта на товар = новая заявка» (commit 1688164, 2026-07-16)
Дыра: детектор форсил post_sale на «Прошу **отгрузить**», а гарды нового заказа промахивались — количество ловилось только как `\d+ шт` (а бывает «12 **демпферов**»), и в NEW_ORDER_MARKERS было «выставить сч», но НЕ «**пришлите** счёт». Кейс <адрес>: «Пришлите счет на 12 демпферов … Прошу отгрузить на пост оплате» → ошибочно post_sale, заявка не создана. **Фикс — `looksLikeInvoiceRequest($haystack)`** (вызывается в конце обоих методов, `return null` при совпадении): regex `/сч[её]т\s+на\s+(оплат|\d)/u` — запрос выставить/прислать счёт на оплату ИЛИ на количество товара = инициируется продажа. НЕ матчит «счёт-**фактуру**» (после «сч[её]т» дефис, а не « на » — это закрывающий документ). Ремедиация письма — `EmailToRequestPromoter::promote($email,$actorUserId)` (кнопка «Создать заявку» на /dashboard/mail): создаёт Pending-заявку + ParseRequestItemsJob (→ авто-назначение), клиенту НИЧЕГО не шлёт, исходную категорию хранит в detected_artifacts (override-аудит). Кейс промоутнут в M-2026-8503 (12×M05124).

### Пост-LLM override «просит счёт → не постпродажа» (2026-07-31, кейс M00965/ЗИПИС)
Тот гард выше был ТОЛЬКО в детекторе (pre-LLM short-circuit): если detect() вернул null, решает LLM — а gpt-4o сам ставил `post_sale` на «Прошу прислать **счёт на оплату** и поставить на комплектацию по позиции M00965… сможем подойти забрать?» (цеплялся за «комплектацию/забрать», терял «счёт на оплату»). Детектор тут корректно возвращал null (счёт-на-оплату гард), но LLM был последним словом. **Фикс:** (1) `looksLikeInvoiceRequest` расширен — кроме `/сч[её]т на (оплат|\d)/` ловит глаголы-просьбы `(выстав|пришл|присла|вышл|выслать|оформ|подготов)\w* сч[её]т`, «счёт-фактуру» вырезает до проверки; выставлен публично как `PostSaleFulfillmentDetector::requestsInvoiceToPay(EmailMessage)`. (2) В `MailCategoryClassifier` ПОСЛЕ ответа LLM: если `category===PostSale && requestsInvoiceToPay($message)` → форсим `client_request` (conf 1.0), reasoning с пометкой override. Проверено на письме id 63430 (post_sale→client_request). Регрессия «пришлите счёт-фактуру» остаётся post_sale. Сам кейс команда обошла руками (M-2026-10445 создана Ежовом).

## client_request vs post_sale — граница «выставить счёт» (тикет M-2026-1534)
- «Прошу **выставить счёт** [на заявку N]» БЕЗ Re:/цитаты, особенно с приложенной спецификацией → `client_request` (новый заказ на оформление, новая заявка). НЕ post_sale.
- `post_sale` про счёт — ТОЛЬКО ЗАКРЫВАЮЩИЕ документы (УПД/счёт-фактура/акт/ТОРГ-12) по уже ОПЛАЧЕННОМУ заказу.
- **Голый номер в теме/тексте («1272», «заявка 1272») — это внутренний номер заказа КЛИЕНТА, а НЕ наш `M-2026-NNNN`**; сам по себе не означает существующую сделку. gpt-4o путал это и ставил post_sale. Правила — в `app/Prompts/Mail/CategorizeIncomingPrompt.php` Правило 11 + примеры 15/16.
- ВАЖНО различать: `thread_reply` = «переписка в старой заявке» (приклеивается к существующей); `client_request` = новая отдельная заявка. «Выставить счёт на новый заказ» = client_request, НЕ thread_reply.

## Attention-флаг PostSale (пул)
`AttentionService::onPostSaleMessage` теперь поднимает `AttentionReason::PostSale` (level=1, sticky, снимается onManagerOpened) для всего набора `awaiting_invoice/invoiced/paid/closed_won` (раньше только closed_won; для paid/closed_won — сознательный обход silentStatuses). Пул-бакет «🛒 Постпродажа» и счётчики (`Pool::postSaleStatuses()`, `statusesForBucket('postsale')`, counts, `myPostSale`) расширены на тот же набор.

## Ремедиация инцидентов (паттерн)
Ошибочно созданную заявку закрывал через `RequestStateService::transitionTo(..., ClosedLost, null, ['closed_lost_reason'=>'manual_other','closed_lost_comment'=>...], systemTransition: true)`. Письмо перепривязывал через `EmailMessage::forceFill(['related_request_id'=>...])`. Новую заявку из письма создавал `IncomingMailProcessor::processIfRequest($msg)` (после detach: related_request_id=null + нужная category) — он сам диспатчит ParseRequestItemsJob (парсинг вложений + авто-назначение). NB: `onPostSaleMessage` — no-op для не-closed_won до правки 5ed4272; учитывать при ручной ремедиации.

## Коммиты сессии (2026-06-01)
33802f4 (post-sale attach к paid/won + потом убранный from_email guess), e607c3e (промпт комплектация), 9f1a493 (детектор, потом упрощён), 0271250 (детектор только по контенту + убран from_email guess), 5ed4272 (PostSale флаг на paid/invoiced + пул-бакет), 40156fc («выставить счёт на заявку N» → client_request).

## Пост-продажа в ЗАКРЫТУЮ-УСПЕХОМ сделку: авто по треду + ручной заворот (fix f8a7343, 2026-08-19, M-2026-11863→11309)
Ответ клиента в тред closed_won дела (доставка/документы, «подготовка поручня») выглядел как новая заявка → плодился фейк. Реанимировать won нельзя, плодить фейк — тоже. Решение — заворачивать переписку в само выигранное дело:
- **АВТО:** `InboundReplyLinker::tryLink` Case 2 (терминальный родитель) — если родитель = **ClosedWon** и связь по заголовку (In-Reply-To/References) → прицепить письмо к нему (PostSaleMessage), вернуть заявку, **независимо от категории LLM** (заголовок авторитетнее). closed_lost остаётся на гибриде наследование/реанимация. `MailRouter`: linkedRequest=ClosedWon → `handlePostSaleMessage`+return (статус/парсинг/sticky заморожены, category=post_sale, attention 🛒). Раньше существующий post-sale-attach (стр ~288) был недостижим по заголовку И требовал category===post_sale.
- **РУЧНОЙ:** `PostSaleRerouteService::reroute(source, target, by)` — письма фантома→target (входящие post_sale), attention, фантом УДАЛЁН; гарды: target=won/paid, source без своих КП/счетов, права/клиент. UI — режим «🛒 Завернуть в закрытую сделку» в MergeDialog (кандидаты = closed_won/paid клиента, `reroutePostSale`, `postSaleMode`). Симметрия авто-крону `CheckInheritanceJob::rerouteAsPostSale` (тот удаляет фантом по detectDocsOrFulfillment). Зачистка кейса: 9 писем→11309, фантом удалён.
