---
name: intent-classification-selfheal
description: "Догоняющая классификация интента входящих после сбоя AI (429/quota): email_messages.intent_classified_at + cron mail:classify-intent-pending"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Входящие ответы клиента классифицирует `InboundIntentClassifier` в `MailRouter::route` (intent → DetectorType → `AiDecisionService::recordSuggestion` → авто-переход статуса: under_review/invoice_request/postponed/decline/clarification_response). Классификатор **fail-safe**: при сбое LLM (OpenAI 429/quota) `classify()` возвращает `null`, решение НЕ пишется, статус НЕ меняется, **ретрая не было** → заявка зависала в Quoted, хотя клиент ответил «на согласовании»/«выставите счёт»/«отказ». Канон-кейс **M-2026-2302** (OpenAI 429 15.06: лог `InboundIntentClassifier: LLM call failed (non-fatal) 429` для msg#20781; крон `mail:categorize` догнал только КАТЕГОРИЮ — интент он не трогает). Статус «На согласовании» = `RequestStatus::UnderReview`; интент `under_review_acknowledgment` (прим. в `ClassifyClientResponsePrompt`).

**Само-залечивание (commit 5944845…f9db7e2, 2026-06-19):**
- `email_messages.intent_classified_at` — ставится ВНУТРИ `InboundIntentClassifier::classify()` ТОЛЬКО когда LLM реально ответил (валидный JSON, включая unclear). Транзиентный сбой (429) выходит `return null` ДО отметки → остаётся NULL.
- Крон `mail:classify-intent-pending {--limit=100,--days=3,--dry-run}` (everyFifteenMinutes) — берёт inbound `category=thread_reply` + related_request_id + `intent_classified_at IS NULL` + свежие (days) + БЕЗ существующего `inbound_*` ai_decision (whereNotExists) → `isApplicable` (eligible-статусы) → `classify` (сам стампит при успехе) → если `type!==null` → `recordSuggestion` (применит переход). new_request/unclear не применяются. Не applicable → стампим, чтобы не перебирать.
- **Бэкфилла НЕТ** (заказчик: не откатывать заявки к старому состоянию): при деплое разово проштамповали весь существующий inbound (`intent_classified_at=now()`, 14389 шт.) → крон стартует «чистым» и лечит ТОЛЬКО будущие сбои. Старые thread_reply без решения (исторически unclear) повторно НЕ гоняются.
- M-2026-2302 поправлен вручную (→ under_review), бэкфилл по 15.06 не делали.

Проверено на проде (транзакция+rollback): null-stamp reply в eligible-статусе → classify вернул inbound_under_review conf0.9 + проставил stamp.
