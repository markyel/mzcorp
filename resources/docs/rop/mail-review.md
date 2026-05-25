---
title: Авто-отклонённые
order: 40
excerpt: Разбор писем, которые AI пометил irrelevant — реоткрытие как заявка или подтверждение.
roles: [head_of_sales, secretary]
---

# Авто-отклонённые

`/dashboard/mail-review` — все входящие письма, которые AI-категоризатор пометил как `irrelevant` и которые **не привязаны к существующей Request**. Здесь вы выборочно реоткрываете ошибочно отклонённые.

## Что в списке

- Только `direction=inbound`.
- `categorized_at IS NOT NULL` (категоризатор отработал).
- `related_request_id IS NULL` (linker не нашёл существующего треда).
- `category = irrelevant`.

Фильтры:

- **Окно по времени:** today / 7d / 30d / 90d / all.
- **Поиск:** по теме / from_email / from_name (ilike).

## Два действия

### «Это заявка» (`reopenAsRequest`)

Создаёт `Request` со статусом `Pending`, переопределяет category=irrelevant в audit-журнале (`detected_artifacts.manual_reopen_as_request`), диспатчит парсер позиций. Дальше pipeline обычный: парсер найдёт позиции → персистер сохранит → auto-assign выберет менеджера.

Используйте когда AI ошибочно пометил **реальный** клиентский запрос как нерелевантный (типичные кейсы: `undisclosed-recipients:;` в `Кому`, BCC, корпоративный RFQ с шаблонным телом).

### «Подтвердить отклонение» (`confirmRejection`)

Audit-метка в `detected_artifacts.manual_confirm_rejection`. Письмо остаётся в auto-rejected без Request — но теперь есть запись о том, что РОП посмотрел и согласился. Полезно для статистики качества AI.

## Типичные причины ложных irrelevant

- **`undisclosed-recipients:;` в To** — `UnintendedRecipientDetector` срабатывает на «не в нашем треде, нет нас в To/CC». См. unintended-recipient правило в [категоризаторе](/docs/rop/distribution).
- **HTML-only письма без body_plain** — раньше категоризатор молча падал, починено `CategorizeIncomingPrompt` через `EmailTextCleanerService::htmlToText`.
- **Image-only письма** (фото + короткое тело) — LLM получает мало текста, может занизить уверенность.
- **Корпоративный RFQ** (Транснефть, РЖД и т.п.) — короткое шаблонное тело + PDF со спецификацией. По правилу 9 промпта должен быть `client_request`, но иногда срабатывает unintended-recipient override.
- **OpenAI fail** — 429/503/timeout. Если категоризация ушла в `empty` → письмо застряло без category. С 25.05.2026 это лечит scheduled `mail:categorize --all` каждые 5 минут и circuit-breaker с алертом.

## Что **не** видно в этом разделе

- Письма с `category=client_request` без Request — это **другая** проблема (processIfRequest упал). Их разбирает scheduled `requests:recover-unassigned`.
- Письма-reply, не привязанные к заявке — это **deferred reply**, разбирает scheduled `mail:relink-deferred`.
