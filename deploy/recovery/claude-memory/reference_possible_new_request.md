---
name: possible-new-request
description: "Подсказка «возможно новая заявка»: клиент в пост-КП треде просит счёт/КП на новую номенклатуру — не форкаем молча, а предлагаем менеджеру кнопку"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Проблема (кейс M-2026-5397 / письмо 36679): клиент отвечает в старом треде («RE: счёт 6485»), но просит выставить счёт на СОВЕРШЕННО ДРУГИЕ позиции = новая заявка. Reply-linker жёстко привязывает к существующей заявке; интент-классификатор (InboundIntentClassifier) помечает `additional_items` → `inbound_extension` (auto-applied) = «расширение», и новая сделка молча тонет в старой. Промпт намеренно смещён к extension («расширение безопаснее, обратимо») + не смотрит на стадию заявки. **Механизм форка УЖЕ есть** (intent `new_request` ≥0.8 → `RequestExtensionService::spinOffNewRequest`), но классификатор выбрал extension.

Решение — **Approach B (подсказка, НЕ авто-форк; заказчик выбрал именно B)**, commit dede605, на проде. Авто-классификацию НЕ трогали (inbound_extension отрабатывает как раньше), добавили параллельную подсказку менеджеру:
- `DetectorType::InboundPossibleNewRequest` ('inbound_possible_new_request'): label «Клиент: возможно новая заявка», `targetStatus()=null`, НИКОГДА не авто-применяется (auto_mode off по умолчанию). Миграции нет (значение — строка).
- `RequestStatus::isPostQuote()` — quoted/under_review/awaiting_invoice/invoiced/paid/closed_* (заявка достигла КП или дальше).
- `MailRouter` (после блока авто-форка new_request): если `linkedRequest->isPostQuote()` И intent `additional_items` (ИЛИ `new_request` ниже NEW_REQUEST_CONFIDENCE=0.8) → `aiDecisions->recordSuggestion(InboundPossibleNewRequest,...)`. Переиспользует уже посчитанный $intentResult (без лишнего LLM). NB: intent-классификатор `isApplicable` не гоняется на Paid/Closed → там подсказки не будет (gap, ок).
- `AiDecisionService::apply` — спец-кейс до общей ветки targetStatus: `applyPossibleNewRequest` → `spinOffNewRequest` (создать ОТДЕЛЬНУЮ заявку); при успехе деактивирует позиции исходной заявки с `source_email_message_id`=это письмо (их добавил inbound_extension — иначе задвоятся), decision→ManuallyConfirmed. Если spin-off вернул null (нет позиций) — исходную не трогаем.
- Плашка на карточке ([detail.blade](resources/views/livewire/requests/detail.blade.php), рядом с др. AI-подсказками, `$canManage`): иконка 🔀, кнопка «🔀 Создать новую заявку» (wire:confirm) → applyAiDecision → spin-off; ✕ = dismiss.

Только вперёд: 5397 НЕ бэкфилили (заказчик: оставить как есть). Связано: [[postsale-routing]], [[forwarded-requests]] (EmailToRequestPromoter — ручное создание из письма). Пороги/логика классификатора не менялись — если позже захотят агрессивнее, вариант A = учёт стадии в самом промпте InboundIntentClassifier.
