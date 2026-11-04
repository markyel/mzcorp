---
name: request-merge
description: "Слияние заявок-дублей (RequestMergeService + MergeDialog): что переносится, правила статусов и режим «другой e-mail отправителя» для привилегированных"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Слияние дубля (loser) в основную заявку (winner): `App\Services\Request\RequestMergeService` + UI `App\Livewire\Requests\MergeDialog` (кнопка «⊌ Объединить заявки» в карточке заявки).

**Что делает merge** (одна транзакция): все `email_messages` loser'а → winner; активные `request_items` копируются с дедупом по normalized `(parsed_article|parsed_name)` — дубли пропускаются, перенесённые оригиналы у loser'а гасятся `is_active=false`; `clarification_batches` → winner; loser → `closed_lost` + `closed_lost_reason=duplicate` + `merged_into_id`/`merged_at`; audit `merge_from`/`merged_into` в `request_state_changes` обеих. НЕ переносятся: subject, client_email, assigned_user_id (winner сохраняет свои). `preview()` — те же подсчёты без записи.

**Правила статусов (уточнены 2026-07-17, commit d143195):** раньше обе заявки должны были быть active — из-за чего типовой дубль было не свернуть. Теперь:
- **winner** должен быть живым: НЕ `Paused/ClosedWon/ClosedLost/Pending/Paid` (`WINNER_SILENT_STATUSES`);
- **loser**: `closed_lost` **РАЗРЕШЁН** (дубль обычно уже закрыт — авто-закрытием или руками), запрещены `Paused/ClosedWon/Pending/Paid` (`LOSER_SILENT_STATUSES`) — там состоявшаяся сделка и деньги, прятать внутрь другой заявки нельзя.

**Режим «другой e-mail отправителя» (кросс-клиентское слияние):** тот же реальный заказчик пишет с другого ящика. Канон-кейс: счёт запросили с почты А (M-2026-8762, `<адрес>`), обновить его с новыми условиями доставки просят с почты Б (M-2026-8787, `<адрес>`, заведена вручную из постпродажной переписки).
- Сервис: `merge()/preview()` принимают `bool $allowCrossClient` (default false → старое поведение); `isCrossClient()` сравнивает нормализованные client_email; `validate()` бросает, если кросс-слияние запрашивает НЕ привилегированный.
- **Права: только head_of_sales / director / admin** (решение заказчика — риск склеить двух разных заказчиков). Дублируется в `MergeDialog::canCrossClient`.
- UI: **по умолчанию список кандидатов НЕ меняется** (active + тот же client_email + LZ-REQ-маркеры). Отдельная кнопка «✉ Другой e-mail отправителя» (видна только привилегированным) → `crossClientCandidates()`: поиск по **номеру заявки / теме / client_email среди ВСЕХ** заявок + автоподмешивание заявок той же `organization_id`, статусы active **+ closed_lost**. Без строки поиска и без организации у winner — пусто (иначе это «все заявки системы»). Расхождение почты подсвечивается на карточке кандидата и жёлтой плашкой в превью перед подтверждением.

**Грабля деплоя:** blade диалога использует amber-классы — они уже были в сборке, но при добавлении НОВЫХ произвольных Tailwind-классов нужен `npm run build` на проде (см. [[tailwind-arbitrary-classes]], [[prod-deploy]]).

**Ремедиация дублей задним числом** (tinker на проде): если winner закрыт — сперва `RequestStateService::reanimate()` (вернёт на peak-веху), потом `merge()`. Так свёрнут дубль из [[inheritance-reanimation-hybrid]] (M-2026-8874 → M-2026-7976). Связано: [[request-access-delegation]] (гейты доступа), [[clients-section]] (organization_id).

## Гард против цикла + распутывание (fix 1e2b4a0, 2026-08-19, M-2026-10755↔8001)
Слияния могли образовать ЦИКЛ: 8001→10755 и 10755→8001 (обе `merged_into_id` друг на друга). Следствие: КП №360910 распарсились нормально (OutboundQuote matched), но висели на проигравшем дубле 8001, а раздел КП фильтрует по `request_id` → на 10755 пусто; плюс сама 10755 (in_progress, активная) числилась «слитой». **Гард** (`RequestMergeService::checkConflicts`): отклонять слияние если `winner->merged_into_id !== null` (winner сам уже слит → цикл/цепочка) — сливать только в конечного победителя. **Распутывание цикла руками**: определить настоящего победителя (активный статус + есть позиции — тут 10755 in_progress, 3 позиции) → обнулить ЕГО `merged_into_id`/`merged_at`; проигравший (closed_lost/duplicate, 0 позиций) оставить с `merged_into`=победитель; перенести застрявшие outbound_quotes/quotations/invoices loser→winner. Свип осиротевших КП: `outbound_quotes oq JOIN requests r ON r.id=oq.request_id WHERE r.merged_into_id IS NOT NULL AND r.merged_into_id<>oq.request_id`. Осталось 3 в старых циклах (1508↔3233, 2598↔2597, 7906↔7930).
