---
name: clients-section
description: "Раздел «Клиенты»: реестр организаций + контактов (M:N email↔организация), реквизиты/скидка, бэкфилл, статистика"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Раздел `/dashboard/clients` (реализован 2026-06-17). Доступ и редактирование — **все роли** (`role:manager,head_of_sales,secretary,director,admin`).

## Модель данных
- **`organizations`** — name, inn, kpp, address, `requisites_text` (банк/р-с/прочее для КП/счёта), `discount_percent`, notes. Модель `App\Models\Organization`.
- **`client_contacts`** — email (uniq), full_name (ФИО), phone, notes. Модель `App\Models\ClientContact`.
- **`organization_contact`** — pivot M:N (один email ↔ много организаций и наоборот). Relation `Organization::contacts()` / `ClientContact::organizations()` (belongsToMany, explicit pivot 'organization_contact').
- `Organization::contactEmails()` — lowercase email'ы для подсчёта статистики по `requests.client_email`.

## UI (контакт-центрично — основная единица email заказчика)
- `Clients\Index` (`livewire.clients.index`) — ДВЕ ВКЛАДКИ: **Контакты** (по умолчанию; все email'ы: ФИО/телефон/#организаций/#заявок, поиск; computed contacts + contactRequestCounts одним groupBy) и **Организации** (name/inn/kpp/контакты/скидка, инлайн-создание).
- `Clients\Contact` (`livewire.clients.contact`, роут `/dashboard/clients/contact/{contact}`) — карточка email: ФИО/телефон/заметки (правка), привязанные организации (чипы), статистика по этому email (заявки/won/lost/КП/счета), последние 25 заявок.
- `Clients\Show` (`livewire.clients.show`, роут `/dashboard/clients/org/{organization}`) — реквизиты+скидка; контакты (добавить/инлайн-edit ФИО+тел/отвязать); статистика; удаление.
- Rail-пункт «◈ Клиенты» (left-rail.blade, всем ролям) + active-матч `clients.*` в layouts/app.blade.

## Персональные автоуведомления клиента (commit 9db9995→07d7e79, 2026-06-22)
Тумблеры по 7 типам `ClientNotificationType` (галочка = слать, снято = заглушить; «Напоминание после КП» = `QuoteFollowupReminder`) на **ДВУХ карточках**:
- **`Clients\Show`** (организация) — у КАЖДОГО контакта кнопка «🔔» (+бейдж «−N») раскрывает панель (`toggleNotifPanel(contactId)`, computed `notificationOptouts` = lower(email)→suppressed[]).
- **`Clients\Contact`** (e-mail-центричная карточка, роут `/dashboard/clients/contact/{id}`) — карточка «🔔 Автоуведомления» всегда видна (single email). **Важно: контакт без организации доступен ТОЛЬКО здесь** (в `Show` его нет) — кейс contact#570 `<адрес>`, orgs=0.
- Общая логика в **`ClientNotificationOptoutService`**: `toggle(email, type, ?byUserId)` (flip + удалить пустую запись без комментария) / `suppressedFor(email)` / `suppressedForMany(emails)` — единый источник для админ-страницы `/dashboard/notification-optouts`, обеих карточек и гарда `isSuppressed` в `ClientNotificationService::dispatch`.

Пишет в **ту же `client_notification_optouts.suppressed_types` по e-mail** — настройка едина с админкой в обе стороны. Опт-аут глобален ПО E-MAIL (не по орг.): один email у нескольких орг. → общая (письмо и так уходит на конкретный `client_email`). Доступ — все роли. Без миграции (таблица из commit 4989f71). Прод-проверка: contact#570 (без орг.) тумблер «Напоминание после КП» → isSuppressed=TRUE, suppressedFor=["quote_followup_reminder"] (rollback-dry-run).

## ГЛАВНЫЙ источник реквизитов — `clients:extract-requisites --apply`
Реквизиты организаций тянутся НЕ из UI-Quotation (их всего 32, реквизиты пустые), а из **внешних КП/счетов в исходящей почте** — `OutboundQuote` (3039 шт: 1013 счетов + 2026 КП, все с PDF-attachment). В PDF 1С есть блок «**Покупатель: <Название>, ИНН …, КПП …, <адрес>**» — парсим его (покупатель = ИНН ≠ нашего `config services.company.inn` 7715802492). Апсёрт `Organization` по ИНН + связь с контактом (email заявки). Идемпотентно через `payload.requisites_extracted=true` (сброс флага = `outbound_quotes.payload - 'requisites_extracted'` → переразбор). Парсер: ТОЛЬКО чёткий блок «Покупатель: <Название>, ИНН …, КПП …, адрес» (наш ИСХОДЯЩИЙ клиентский счёт/КП). **Грабля (важная):** bare-ИНН фолбэк (брал любой не-наш ИНН) давал мусор — 144 плейсхолдера «ИНН N» без названия из НЕпрофильных документов в исходящей почте (входящие счета поставщиков где покупатель МЫ, банковские выписки с ИИН сотрудников, инвойсы иностранцам типа UAB/LT). Проверка 18 шт → 17 мусор. Фолбэк УБРАН (commit be43012), 144 удалены. **Итог: 266 реальных организаций, 244 с КПП, 258 с адресом** — почти все с полными реквизитами. Не «дожимать ИНН без названия»: если названия нет — это не клиентский счёт, дожимать нечего. **Грабля 2 (артикул как имя):** парсер мог выхватить из блока артикул («ип 6311-2RS» вместо «<организация>» — кейс i.turenkov/#71), и fill-if-empty закреплял мусор. Фикс: `isJunkName` (3+ цифры подряд без кавычек = артикул; нет слова ≥3 букв = мусор) + перезапись мусорного/плейсхолдерного имени хорошим при переразборе. После фикса 9→2 (оставшиеся 2 без чистого документа → честный плейсхолдер «ИНН N»). Сброс флага + `clients:extract-requisites --apply` чинит такие. Итог: 267 орг.

## Бэкфилл контактов/имён (`clients:backfill --apply`, идемпотентно)
- Контакты — из внешних `requests.client_email` (+ ФИО/телефон, свежие первыми).
- Организации — из реквизитов отправленных КП (`Quotation.recipient_name/inn/address/card_text/discount`) И `requests.client_company`.
- Связи email↔организация — по факту использования (КП/заявка связывает email с организацией).
- **Грабля 1 (тип получателя):** `Quotation.recipient_name` = СМЕСЬ ФИО получателя и компаний, причём компании часто БЕЗ маркера ООО/ИП («СтайлЛифт», «СП Интерлифт», «Liftway.ru»). Поэтому организацией считаем всё, что НЕ похоже на ФИО (`looksLikePerson`: 2–3 слова с заглавной, кириллица/латиница; орг-маркер/кавычки = не ФИО); `client_company` — всегда организация (assumeOrg). Строгий ООО-фильтр давал только 9; «не-ФИО» — 13. ФИО остаются только контактами.
- **Грабля 2 (нет реквизитов в источнике):** в системе всего **32 Quotation** (UI-билдер), и реквизиты в них почти не заполнены: `recipient_inn`=0, `recipient_card_text`=0, address=1 на проде. Backfill'ить реквизиты НЕЧЕМ — карточки организаций пустые по реквизитам не из-за бага, а потому что данных нет; ИНН/КПП/банк вносят вручную. Переносится только то, что есть: имя, иногда discount (ЛифтСтрой 10%). Основная масса клиентов — email-only (826 контактов, 13 орг).
- Сброс/переразбор: `Organization::query()->delete()` (cascade чистит pivot, контакты сохраняются) → `clients:backfill --apply`.

## Точная привязка заявки к организации — `requests.organization_id` (2026-06-18, commit a96df34)
FK `requests.organization_id` (nullable, nullOnDelete) + `Request::organization()` / `Organization::requests()`. Резолвер `App\Services\Clients\RequestOrganizationResolver` (консервативный — гадать не пытается): `resolve()` по `client_company` (точное совпадение ровно с одной орг.) → по `client_email` (контакт привязан ровно к одной орг.); неоднозначно/нет данных → null. `attach()` ставит если ещё не задан; `backfillForEmailLink(org,email)` — set-based UPDATE ещё не привязанных заявок email ПРИ ПОЯВЛЕНИИ связи email↔орг (только если email однозначно у этой орг.). Авто-привязка во ВСЕХ точках создания: `IncomingMailProcessor` (повторные клиенты), `EmailToRequestPromoter`, `RequestSplitService` (наследует organization_id родителя), `Clients\Show::addContact` (UI), `clients:backfill` (новый шаг 4) + `clients:extract-requisites`. **Статистика карточки организации (`Clients\Show`) переведена на `organization_id` + fallback по email** для ещё не привязанных (без регресса до бэкфилла, без двойного счёта — заявки, явно привязанные к ДРУГОЙ орг., в fallback не попадают). Блок «Заявки организации» + столбец «Заявок» (`withCount('requests')`) в списке. **Прод-бэкфилл 2026-06-18:** 2660/4973 заявок привязано к 220 орг. (здоровый long-tail, топ «Метеор Лифт Москва» 324). Остальные null — email не привязан ни к одной орг. (растёт по мере `extract-requisites`). **Грабля:** у части орг. ИНН реальный, но НАЗВАНИЕ мусорное (артикул, напр. «ип DDE со шкивом V» — 239 заявок); привязка по ИНН корректна, чинить имена руками/повторным `extract-requisites`. Контакт-карточка (`Clients\Contact`) и список контактов остались по email (история контакта = его email, это и так точно).

## Не сделано (следующие этапы)
- **Авто-создание/обновление `ClientContact` ПРИ создании заявки** — сейчас контакты только из `clients:backfill`/`extract-requisites`, поэтому для совсем новых клиентов привязка organization_id доезжает на следующем бэкфилле. Сделать контакт-апсёрт в `IncomingMailProcessor` (тогда реестр живой).
- **Ручной override привязки в карточке ЗАЯВКИ** (`requests.organization_id` сейчас ставится только авто; нет UI «Организация: X [изменить]» на детальной странице заявки).
- Автоподстановка реквизитов/скидки организации в КП/счёт. Связано с [[catalog-matching]] и [[revival-offer]] (там тоже client_email).
