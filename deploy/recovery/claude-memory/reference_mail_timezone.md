---
name: mail-timezone
description: "Таймзона времени письма (sent_at): рассинхрон MSK/UTC — причина, фикс в MessagePersister::extractDate, источник истины headers[date], бэкфилл-команда"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

**Рекуррентная грабля «рассинхрон времени письма».** `app.timezone = Europe/Moscow`. Eloquent сохраняет datetime как wall-clock БЕЗ конверсии и читает обратно как app.timezone. Поэтому если входящему `EmailMessage.sent_at` присвоить Carbon в чужой tz (заголовок Date обычно UTC «+0000»), в БД ляжет UTC-wall-clock (напр. `08:29`), а отобразится как `08:29 MSK` → −3 часа.

- **Фикс (2026-06-05, коммит f650362):** `App\Services\Mail\MessagePersister::extractDate()` делает `->setTimezone(config('app.timezone'))` перед возвратом → хранится корректный MSK-момент. Только вперёд.
- **Источник истины по времени** — `EmailMessage.headers['date']` (ISO8601 с оригинальным offset, напр. `2026-06-05T08:29:08+00:00` или `...+03:00`). `sent_at` = время ОТПРАВКИ (Date-заголовок), не время получения нами (отдельного received_at нет; ближайший анкер ингеста — `created_at`).
- **Бэкфилл старых записей:** команда `mail:fix-sent-at-tz` (коммиты 898651b/ed8014a) — пересчитывает sent_at из headers[date]→MSK, dry-run по умолчанию, пропускает записи без явного offset. На 2026-06-05 dry-run: 3157 из 9039 расходятся (в ОБЕ стороны: старые письма были +3ч, новые −3ч). **Заказчик решил НЕ применять** (правка только на будущее) — команда лежит неактивной, к крону не привязана.
- Отображение в blade — обычный `$em->sent_at?->format('d.m.Y H:i')` (mail/index, mail-review): доп. конверсия в шаблоне НЕ нужна, т.к. момент уже корректен после фикса ингеста.
