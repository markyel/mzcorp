# Авто-закрытие заявки по письму-отказу клиента

Единственный механизм авто-закрытия заявки в `closed_lost` по ответу клиента:
`InboundIntentClassifier` → intent `decline_with_reason` → `DetectorType::InboundDecline`
→ `AiDecisionService::recordSuggestion` → auto_apply (event `ai_auto_apply`, by_user_id NULL)
→ `RequestStateService::transitionTo(closed_lost)`.

Параллельного пути нет. Проверено выгрузкой `request_state_changes` (2026-05-29):
все system-закрытия в closed_lost по отказу — только через `ai_auto_apply`.
(Отдельно `system_close_lost` из `pending` — другой механизм, cron-добивка зависших,
к письмам-отказам отношения не имеет.)

## Гейт по статусу
`InboundIntentClassifier::ELIGIBLE_STATUSES` — только в этих статусах классифицируется
ответ клиента (`isApplicable()`):
**new, assigned, in_progress** (ранние — добавлены 2026-06-23, коммит e38fdea),
quoted, under_review, postponed_until, awaiting_client_clarification,
**awaiting_invoice, invoiced** (последние два добавлены 2026-05-29, коммит 38808b4).

**Ранние статусы — только decline (коммит e38fdea, кейс M-2026-4710):** клиент написал
«задвоила запрос, можете закрывать», но заявка была `assigned` (КП не было) → отмена ДО
квотирования не ловилась. Фикс: +new/assigned/in_progress в ELIGIBLE_STATUSES. Чтобы не
плодить преждевременные авто-переходы на ранние ответы — в `EARLY_STATUSES`
(new/assigned/in_progress) `classify()` даунгрейдит ВСЁ кроме `InboundDecline` (и
new_request) в `InboundUnclear`: invoice_request/additional_items/under_review/postpone
на ранней стадии оставляем менеджеру. 4710 закрыта разово через
`classify()`+`recordSuggestion()` (conf 1.0, reason client_declined_other).

Для не-decline intents из invoiced/awaiting_invoice state-machine безопасно отклоняет
переход (DomainException → suggestion Dismissed). `invoiced→closed_lost` и
`awaiting_invoice→closed_lost` разрешены в `RequestStatus::allowedTransitions()`.

## Кейс M-2026-2389 (2026-05-29)
Клиент после счёта написал «прошу аннулировать счёт, поищу из наличия», но заявка
осталась `invoiced` — потому что `invoiced` не было в ELIGIBLE_STATUSES → письмо не
классифицировалось. Фикс: +invoiced +awaiting_invoice. Заявку 2389 закрыли разово,
переклассифицировав письмо 7008 через `classify()`+`recordSuggestion()` (reason
`client_declined_other`, conf 0.9).

## Промпт
`ClassifyClientResponsePrompt` распознаёт decline_with_reason **независимо от статуса**
(статусом гейтится только clarification_response). Reason taxonomy в промпте:
client_declined_price / timing / competitor / other. «Аннулировать счёт» даёт
`client_declined_other`, НЕ `invoice_cancelled` (отдельный маппинг в промпт не добавлен).

## Имена колонок (часто путаются)
- `request_state_changes`: from_status, to_status, **by_user_id** (актор, NULL=system),
  event, payload(jsonb). НЕ `changed_by_user_id`.
- `requests`: код заявки — **internal_code** (НЕ `code`).
- `ai_decisions`: detector_type, status, request_id, email_message_id, confidence,
  payload, applied_at, applied_by_user_id, override_to_status.
- письма — таблица **email_messages**, FK на заявку — **related_request_id** (НЕ request_id),
  дата — sent_at, флаг разбора — classified_at.
