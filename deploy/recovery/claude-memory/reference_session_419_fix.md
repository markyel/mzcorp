---
name: session-419-fix
description: "419 «This page has expired» при отправке ответа поставщику — НАСТОЯЩАЯ причина: computed messages() в Livewire-компоненте коллизирует с валидацией Livewire → TypeError → abort(419). Плюс вторичные фиксы сессии/лимитов."
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-20T13:48:31.417Z
---

## Настоящий корень: имя `messages()` в Livewire (fix, 2026-08-20, Suppliers\Show)

Симптом: «This page has expired», ответ поставщику не отправляется, `POST /livewire-<hash>/update → 419`. В консоли ещё `Alpine Expression Error: $wire.close()` на `notifications.bell` — ЭТО ПОБОЧКА (Livewire батчит компоненты в один POST; когда один падает `abort(419)`, весь батч 419-ит, и колокольчик тоже).

**Причина:** `Suppliers\Show` имел `#[Computed] public function messages()`, возвращающий тред переписки (**Eloquent Collection**). Но `messages` зарезервировано Livewire под кастомные сообщения валидации: `HandlesValidation::getMessages()` делает `array_merge($this->messages, ...)`. `sendReply()` → `$this->validate()` → `getMessages()` → `array_merge(Collection)` → **TypeError** → Livewire ловит и делает **`abort(419)`** (`HandleRequests.php:208`, `catch(\TypeError)`).

**Почему тихо / почему «после файлового поля»:** (1) `abort(419)` = HttpException, Laravel НЕ логирует 4xx, и это НЕ `TokenMismatchException` → CSRF-логи пусты, диагнозы «сессия/www/cookie» мимо. (2) Проявилось после добавления поля `replyFiles`, потому что ВМЕСТЕ с ним я добавил `$this->validate([...'replyFiles.*'...])` — до этого в отправке валидации не было, коллизия не срабатывала.

**Фикс:** переименовать computed `messages()` → `threadMessages()` (+ blade `$this->threadMessages`, + `unset($this->threadMessages)`). ГРАБЛЯ НА БУДУЩЕЕ: НИКОГДА не называть метод/свойство Livewire-компонента `messages()`, `rules()`, `validationAttributes()` иначе как для валидации — это магические имена Livewire. Проверка других компонентов: только Show имел `function messages()`; Procurement/Index зовёт validate() но без messages() — ок.

**Диагностика (как нашли):** `abort(419)` не ловится ни `report(TokenMismatchException)`, ни `report(HttpException)` (shouldntReport). Поймали временным middleware, логировавшим payload update для компонента suppliers.show → увидели `calls:[sendReply]` + статус 419 → нашли `report($e)` в `HandleRequests.php:208 catch(\TypeError)` → в дневном логе `laravel-YYYY-MM-DD.log` лежал полный TypeError со стеком (Show.php:128 validate → HandlesValidation:138 array_merge). Лог сайта = `storage/logs/laravel-<date>.log` (daily), env=production.

## Вторичные фиксы (сделаны попутно, НЕ были причиной 419, но полезны)
- `SESSION_LIFETIME=120→10080` (7д) — 2ч мало для CRM весь день открытой.
- `SESSION_DOMAIN=.mzcorp.ru` + `SESSION_COOKIE=mzcorp_session` — общий cookie на www+non-www (сайт на двух хостах). Разлогинило всех 1 раз. Устранило вторичную боль: мой www→non-www nginx-редирект ловил Livewire-POST от www-origin вкладок (в mzcorp.access.log было 54× POST update→301; 301 на POST теряет тело).
- nginx www-редирект `301→308` (308 сохраняет метод+тело).
- PHP `upload_max_filesize 2M→30M`, `post_max_size 8M→48M` (nginx client_max_body_size=50M) — форма разрешает файлы до 25MB, старые лимиты роняли БЫ загрузку (>2MB фото → PHP отбрасывает тело → тоже 419, но это ДРУГОЙ путь, не текущий баг).

Связано: [[prod-deploy]], [[feedback_duplicated_source_of_truth]] (магические имена Livewire — класс тихих багов).
