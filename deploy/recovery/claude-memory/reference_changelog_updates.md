---
name: changelog-updates
description: "Раздел «Обновления» (changelog): лента изменений для всех ролей, превью на дашборде, бейдж непрочитанного, admin-публикация"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Раздел «Обновления» — лента важных для участников изменений системы. Доступен на чтение ВСЕМ ролям без разделения.

- **Таблица** `changelog_entries`: `title`, `excerpt` (краткое содержание, ≤300, опц.), `body` (markdown), `is_published`, `published_at`, `author_user_id`. Модель `App\Models\ChangelogEntry`: scope `published()` (is_published + published_at≤now), `unreadCountFor(User)`, `previewText($limit)` (excerpt либо начало body через `Str::markdown`→strip_tags). Рендер md — `Str::markdown($body, ['html_input'=>'strip','allow_unsafe_links'=>false])`.
- **Непрочитанное**: колонка `users.updates_seen_at`; бейдж-иконка (мегафон) в `navigation.blade.php` со счётчиком `unreadCountFor`; сбрасывается в `App\Livewire\Updates\Index::mount` при открытии `/dashboard/updates`.
- **Лента** (все роли): роут `updates.index` → `view('updates.index')` → `App\Livewire\Updates\Index`. **Управление** (privileged head_of_sales/director/admin): `updates.manage`/`updates.create`/`updates.edit` → `App\Livewire\Admin\Updates\{Index,Editor}` (CRUD + публикация, live md-preview).
- **Дашборд-превью** (все роли): `Dashboard\Index::latestUpdates` (3 шт), карточка после KPI-полосы (до privileged-виджетов) — заголовок + `previewText`, без даты, ссылка «Все обновления →».
- **Markdown-стили** — встроенный `<style>` в blade (класс `.changelog-prose`), чтобы не требовался `npm run build`.
- **Сидер** `ChangelogSeeder` (idempotent updateOrCreate по title): стартовая запись про распределение заявок. Тексты пишем простым языком (заказчик правил формулировки 2026-06-10).

Деплой такой фичи: `migrate` + `db:seed --class=ChangelogSeeder` + **`route:cache`** (новые роуты) + `view:clear` + `config:cache`.
