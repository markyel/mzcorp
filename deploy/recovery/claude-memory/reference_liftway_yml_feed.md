---
name: liftway-yml-feed
description: "YML-фид цен/наличия MyLift→LazyLift: публичный /feeds/liftway/prices.yml, цена=закупка×markup, is_price_actual"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-26T13:34:09.116Z
---

MyLift выступает ПОСТАВЩИКОМ на площадке LazyLift/Liftway. Отдаём YML-фид «Цены и наличие», LazyLift опрашивает по URL (pull, дефолт раз в 12 ч) и обновляет цену/наличие по коду позиции. Формат из `C:\Work\LazyLift\docs\supplier-integration.md`.

**Endpoint (commit 6462cef, 2026-07-23):** `GET /feeds/liftway/prices.yml` — ПУБЛИЧНЫЙ (без auth, по согласованию с заказчиком), Content-Type application/xml, кэш 15 мин (`Cache::remember liftway_feed:prices_yml`), заголовки X-Feed-Offers/-Generated. Роут в web.php до auth-группы (нужен route:cache на деплое).

**Флаг неактуальной цены — НЕ выкидываем из фида (2026-08-03):** раньше фильтр `is_price_actual=true` выкидывал протухшие позиции — Liftway не отличал «цена протухла» от «нет в прайсе» (фид=подмножество каталога) и продавал по старой цене. Теперь фильтр цены СНЯТ: фид = `is_active=true AND purchase_price>0` (всё, что продаём), и в цикле развилка: **актуальные** — как раньше (`available="true"`, price, count=stock, БЕЗ флага = обратная совместимость); **неактуальные** — остаются в фиде с `available="false"`, `<count>0</count>`, `<price>` = последняя известная (закупка×markup) и `<param name="ЦенаАктуальна">нет</param>` (Вариант A). Когда цена снова актуальна — флаг исчезает, позиция продаётся сама. Liftway трактует «не актуальна» по любому из: param ЦенаАктуальна=нет/false/0/no, param ЦенаНеактуальна=да/true/1, атрибут price_actual=false/0/no/нет; отсутствие=актуальна. Эффект: фид вырос 4656→30028 offers (~3.9 МБ, 25372 неактуальных). Канон-кейс M31543 (LW-0032585): purchase 26585×1.15=30572.75, в фиде `available=false`+флаг. NB: blacklisted-дубли (is_active=false) в фид не попадают — верно. In-transit-фид не трогали (там `is_price_actual=true` остался).

**Доработка 2026-08-24 (СрокПоставки + явный ЦенаАктуальна):** по спеке Liftway добавлен per-item `<param name="СрокПоставки">{N}</param>` из `catalog_items.lead_time_days` (= 1С-поле «СрокПоставки», маппинг CatalogImportCommand:70). ВАЖНО про единицы: в 1С срок хранится в КАЛЕНДАРНЫХ днях (недели×7 — 98% значений кратны 7: 70=10нед, 84=12нед, 14=2нед), а Liftway ждёт РАБОЧИЕ → в фиде конвертируем `×5/7` (70→50, 84→60, 14→10; `max(1,round(cal*5/7))`). Актуальным под-заказным (is_price_actual, stock=0) БЕЗ срока в 1С (~758 поз.) ставим дефолт `config services.liftway_feed.default_lead_work_days` (env LIFTWAY_FEED_DEFAULT_LEAD_WORK_DAYS=50 раб.дн). Пишется только когда >0. Код КП трактует lead_time_days так же как календарные (Editor.php:737 ceil/7=недели). И ЦенаАктуальна теперь ставится ЯВНО у ВСЕХ: `да` для актуальных (раньше опускали — «отсутствие=актуальна»), `нет` для протухших. Причина: спека «ЦенаАктуальна=да иначе оффер не покажется» — Liftway показывает оффер только при «да». Пример актуального: `<offer id="M15862" available="true"><price>233970.24</price><count>0</count><param name="ЦенаАктуальна">да</param><param name="СрокПоставки">84</param></offer>`. Кэш фида сбрасывать `php artisan cache:forget liftway_feed:prices_yml` (иначе до 15 мин старый).

**Фид 3 «Полная карточка» (ТЕСТ, 2026-08-26):** `GET /feeds/liftway/full.yml` (публичный, кэш 15мин, `LiftwayFeedController@full`, `LiftwayFeedService::generateFullYml`). В отличие от prices.yml (sku+price+count) отдаёт ПОЛНУЮ карточку: `<name>/<name_en>/<vendor>(brand)/<vendorCode>(sku)/<model>(brand_article)/<price>(закуп×markup)/<currencyId>RUR/<categoryId>/<picture>(photo_url)/<count>/<description>` + `<categories>` из уникальных part_type + `<param>` (Артикул/Категория/Бренд/Бренды все (brands[])/Артикул бренда/OEM кросс (articles[])/Размещение/Вес/Габариты A-F ненулевые/СрокПоставки раб.дн/ЦенаАктуальна). Лимит `config services.liftway_feed.full_limit` (env LIFTWAY_FEED_FULL_LIMIT=100, 0=без лимита). Себестоимость наружу НЕ уходит. Убраны из карточки мислейбл-поля: form_factor (внутр.код), unit_name (в каталоге = группа узла, не единица). Кэш сбрасывать `cache:forget liftway_feed:full_yml`. Роут — route:cache на деплое.

**Что в фиде** (`LiftwayFeedService::generatePricesYml`, XMLWriter):
- Фильтр: `is_active` + `is_price_actual` + `purchase_price>0` + непустой `sku`. Прод ~4611 позиций (из 4613 is_price_actual — 2 без закупки).
- `<offer id="{sku}" available="true"><price>{закупка×markup}</price><count>{stock_available}</count></offer>`.
- **Цена = `purchase_price × markup`**, markup из `config('services.liftway_feed.markup')` (env `LIFTWAY_FEED_MARKUP`, дефолт 1.15 = закупка+15%). Заказчик выбрал закупка+15% (заметно ниже стандартной `price`).
- `available="true"` у ВСЕХ (все с актуальной ценой поставляемы: в наличии либо под заказ); фактический остаток в `<count>` (0=под заказ).
- Наружу только sku+цена+остаток — БЕЗ себестоимости и карточек (имя/фото у Liftway свои).

**Ключ сопоставления** — `sku` (M-артикул) = «Ваш код» в прайсе Liftway.

**Фид 2 «Поставки в пути»** (commit 5fab9b2, 2026-07-23): `GET /feeds/liftway/in-transit.yml` (публичный, кэш 15 мин, `LiftwayFeedController@inTransit`). LazyLift СМЕНИЛ формат с CSV «Партии поставок» на второй YML-фид: `<offer id><count>N</count><param name="ДатаПоступления">Y-m-d</param></offer>`. Снапшот (полный список в пути; убрали позицию → «в пути» снялось).
- `LiftwayFeedService::generateInTransitYml` из `catalog_items.stock_in_transit` ([{qty,date}], СвободноВПути). Фильтр is_active+is_price_actual+sku+непустой transit. Прод ~956 офферов.
- Позиция = один `<offer>`: `count` = СУММА будущих партий, ДатаПоступления = БЛИЖАЙШАЯ дата (формат несёт одну дату, а партий бывает несколько). Прошедшие даты (date<сегодня) отброшены (уже должны быть на складе).

Обе ссылки для кабинета LazyLift: prices.yml (цены/наличие) + in-transit.yml (поставки в пути). Новую номенклатуру их фид не заводит (вручную на их стороне).
