---
name: press-releases-module
description: "Медиаплан → «Пресс-релизы» (?tab=media&media=press): база СМИ press_outlets, релизы, рассылка по одному письму, ответы редакций через PressReplyHandler"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-10-28T17:31:23.173Z
---

Раздел «Пресс-релизы» во вкладке «Медиаплан» маркетинга (`?tab=media&media=press`, компонент `App\Livewire\Marketing\PressReleases`, только admin). Сделан 2026-10-28.

- Таблицы: `press_outlets` (база СМИ, приоритет A/B/C, emails jsonb — первый не-коммерческий = адрес рассылки, `PressOutlet::COMMERCIAL_EMAIL` отсекает sales@/adv@), `press_releases` (idea→draft→ready→sent→archived), `press_release_sends` (письмо релиз×редакция), `press_mentions` (вышедшие публикации).
- База СМИ собрана 28.10.2026 веб-поиском (70 площадок, адреса только со страниц редакций): `storage/app/marketing/press_media.xlsx` в worktree, загрузка `php artisan press:import-outlets <xlsx>` (ключ — url, ручное отключение не сбрасывает).
- Рассылка: `PressMailingService` → черновик `EmailDraftService::createComposeFree` → `OutgoingMailSender::sendDraft` (подпись автора, без рекламного блока — `detected_artifacts.marketing_block_id = null`), job `SendPressReleaseLetterJob` с паузой `services.marketing.press.send_interval_seconds` (45 с). Ящик по умолчанию `PRESS_MAILBOX_EMAIL` (info@myzip.ru).
- Ответ редакции: `PressReplyHandler` в `RoutingPipeline` сразу после `SupplierReplyHandler`, матч In-Reply-To/References ↔ message_id письма рассылки → stage `press_reply`, категория irrelevant, заявка не создаётся.
- AI-черновик: `PressReleaseWriter` + `WritePressReleasePrompt` (только факты инфоповода и медиапрофиля, цитаты не выдумывает).
- Публикации добавляются вручную (площадка узнаётся по домену ссылки); авто-мониторинг упоминаний не сделан.

Связано с [[mail-client]] и [[direct-negatives]].
