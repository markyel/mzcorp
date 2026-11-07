---
name: supplier-archive-and-manual-search
description: "Архив поставщиков (QuoteParts источник, копия в mzCorp, Supplier::notArchived) и ручной поиск «поставщик, а не адрес» — 07.11.2026"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-11-07T13:59:40.507Z
---

**Ручной поиск поставщика** в запросе цены (заявка → «Поставщики», «Снабжение»): `App\Services\Supplier\SupplierManualSearch` + `partials/supplier-manual-search-results`. Одна строка на организацию → в запрос встаёт представитель (`SupplierMatchService::representatives`: первый контакт `quoting` по `contact_position`), адрес выбирает QuoteParts. Конкретный ящик — опцией «↳ только на …», если искали по e-mail; он уходит явно (`explicitAddresses`). Раньше поиск выдавал адреса, и клик по не-представителю слал запрос мимо выбора QuoteParts.

**Архив** (заказчик 07.11: «mzCorp + QuoteParts», кнопка в карточке, массово в реестре, фильтр «Архив»):
- QuoteParts: `supplier_links.archived_at/archived_reason`, `PATCH /supplier-links/{key}` с `archived` (bool) и `archived_reason`; архивному `RfqService::create` отказывает «Поставщик в архиве», `SupplierBuyer` убирает покупателя из кабинета, список сотрудников — архивные внизу.
- mzCorp: `supplier_organizations.archived_at/_reason/_by_user_id`, `SupplierArchiveService` (сначала QuoteParts, если `inQuoteParts()`, ответ → `SupplierMirrorService::apply`; ответ без поля `archived_at` = старый QuoteParts → отказ). Зеркало копирует архив только при наличии ключа в ответе.
- Фильтр `Supplier::notArchived()` стоит в: подборе по матрице, группах (обе панели), ручном поиске, `QuotePartsPanel`, вкладке QuoteParts, `DispatchPriceMonitorsCommand`. `SupplierRegistry::isSupplier` архив НЕ учитывает намеренно — письма архивного по-прежнему письма поставщика.
- Все 1 772 адреса привязаны к организациям (07.11), архивируется организация целиком.

Связано: [[suppliers-move-to-quoteparts-decisions]], [[quoteparts-dispatch-agents]].
