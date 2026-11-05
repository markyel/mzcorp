---
name: onec-number
description: "Обязательный номер заявки/КП из 1С на каждой заявке: ввод в шапке, смена/сброс только РОП/директор, пометка и фильтр в пуле"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Правило заказчика (2026-07-08, commits 8bdd536..d65b3ed): у каждой заявки должен быть прописан номер заявки/КП из 1С — синхрон с корп. базой + контроль менеджеров.

- Колонка `requests.onec_number` (varchar 64, nullable, index).
- **Шапка заявки** (detail hero, под ID-строкой): зелёный чип «1С: №» либо янтарное поле ввода. Первичный ввод — менеджер (owner/acting/privileged, секретарь нет); **изменение установленного — только РОП/директор/админ** (✎ → режим правки), там же красная кнопка «Сбросить номер» (wire:confirm) → null. Аудит в request_state_changes: события `onec_number_set/changed/cleared` (from=to=текущий статус, payload old/new).
- **Подсказка**: «из письма: N — подставить» = document_number последнего outbound_quotation (СТРОГО quote, НЕ invoice — кейс M-2026-7626: подставлялся номер счёта 7143 вместо КП 360610).
- **Пул**: янтарная пометка «⚠ нет № 1С» под кодом заявки + фильтр `?onec=missing|present` (селект в тулбаре, подсвечивается при missing).
- Методы в `Requests\Detail`: saveOneCNumber/startEdit/cancelEdit/clearOneCNumber/canChangeOneCNumber/oneCNumberSuggestion/applyOneCNumberSuggestion.
- Открытый вопрос: бэкфилл 737 открытых заявок с распознанным номером КП (предложен, заказчик не подтвердил).
