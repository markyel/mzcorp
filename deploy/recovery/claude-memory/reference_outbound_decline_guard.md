---
name: outbound-decline-guard
description: "Автозакрытие closed_lost по «отказу менеджера» (outbound_declined) — гард продолжения и многопозиционных заявок, кейс M-2026-20979, как замерять ложные закрытия"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-10-26T12:17:59.947Z
---

`outbound_declined` (исходящее письмо менеджера = «не наш профиль») автоматически закрывает заявку потерей при confidence ≥ `detector.confidence_threshold` (0.85). LLM-ветка (OutboundDocumentClassifier, gpt-4o-mini) почти всегда даёт 0.9 на любое «к сожалению … не …».

С 26.10.2026 (commit 9604d5f8) любой decline (правила или LLM) проходит `OutboundDocumentDetectionService::guardDecline`:
- продолжение в собственном тексте менеджера (`OutboundDocumentDetector::DECLINE_FOLLOWUP_KEYWORDS` — единый словарь для правил и LLM: «?», «устроит», «готовы», «предлагаю», «в сборе», «по-элементно», «аналог», «из наличия»…) → clarification 0.65 (плашка, не авто);
- в заявке >1 активной позиции → decline 0.8 (ниже порога, менеджер подтверждает);
- промпт получает «Позиций в заявке: N» и правило «отказ = по ВСЕЙ заявке».

Кейс M-2026-20979: «не предложим такой в сборе / готовы по-элементно? + ссылка» закрыт через 7 с после ручного «мяч у клиента». За 60 дней 15 из 142 автозакрытий откатили вручную; реплей нового гарда: 13 из 15 больше не закрываются, 73 явных отказа закрываются как раньше.

Замер ложных закрытий: ai_decisions (detector_type=outbound_declined, status=auto_applied) + request_state_changes from_status=closed_lost с by_user_id в течение 3 дней. `mail:detector-replay` исходящий классификатор НЕ покрывает.

См. [[reference_inbound_decline_autoclose]], [[feedback_duplicated_source_of_truth]].
