# Catalog import: MDB → mzcorp.ru

Регулярная выгрузка корпоративного каталога из локальной Access-базы
(.mdb) в Postgres на проде через `POST /api/catalog/import`.

## Архитектура

```
┌─────────────────────┐    pyodbc   ┌──────────────┐    HTTPS    ┌──────────────────┐
│ Windows Task        │ ──────────▶ │ export_mdb.py│ ──────────▶ │ mzcorp.ru        │
│ Scheduler           │             │ (этот скрипт)│             │ /api/catalog/    │
│ (раз в N минут)     │             └──────────────┘             │ import           │
└─────────────────────┘                                          └──────────────────┘
                                                                          │
                                                                          ▼
                                                       ┌─────────────────────────────────┐
                                                       │ CatalogImportService            │
                                                       │  - upsert по sku + source_hash  │
                                                       │  - soft-delete снятых строк     │
                                                       │  - аудит в catalog_imports      │
                                                       └─────────────────────────────────┘
                                                                          │
                                                                          ▼
                                                       ┌─────────────────────────────────┐
                                                       │ ResolvePendingFromCatalogJob    │
                                                       │  - подбирает RequestItem        │
                                                       │    со статусом                  │
                                                       │    internal_catalog_pending     │
                                                       │  - заполняет имя/бренд из       │
                                                       │    каталога, статус → sufficient│
                                                       └─────────────────────────────────┘
```

## Установка на офисной машине (Windows)

1. **Python 3.10+** — `python --version`. Установить с python.org, отметить
   "Add Python to PATH".

2. **Access Database Engine 2016 Redistributable**:
   https://www.microsoft.com/en-us/download/details.aspx?id=54920
   Скачать `AccessDatabaseEngine_X64.exe` (или x86 если Python 32-bit),
   запустить с флагом `/quiet` если нужен silent install.
   Без него `pyodbc` не подцепит .mdb.

3. **Скопировать `export_mdb.py`** в, например, `C:\catalog-sync\export_mdb.py`.

4. **Зависимости Python**:
   ```cmd
   pip install pyodbc requests
   ```

5. **Получить токен у админа прода**, сохранить как переменную окружения
   (или зашить в `.cmd`-обёртку):
   ```cmd
   setx CATALOG_IMPORT_TOKEN "<хексовая_строка_длиной_64>"
   ```
   На сервере соответствующая запись в `.env`:
   ```
   CATALOG_IMPORT_TOKEN=<тот же токен>
   ```
   Генерация: на проде `openssl rand -hex 32`.

6. **Тестовый прогон вручную**:
   ```cmd
   set MDB_PATH=C:\path\to\catalog.mdb
   set MDB_TABLE=Products
   set API_URL=https://mzcorp.ru/api/catalog/import
   set API_TOKEN=...
   python C:\catalog-sync\export_mdb.py
   ```
   Должен вернуть `HTTP 200` + JSON-ответ с `import_id`, `rows_total` и т.д.

7. **Регистрация в Task Scheduler**:
   - Open Task Scheduler → Create Basic Task.
   - Trigger: Daily / Repeat every 1 hour / Indefinitely (или ваша частота).
   - Action: Start a program.
     - Program: `python.exe` (или полный путь, `where python`).
     - Arguments: `C:\catalog-sync\export_mdb.py`
     - Start in: `C:\catalog-sync\`
   - Settings: Stop task if running longer than 10 minutes;
     If task fails, restart every 5 min, up to 3 times.

## Контракт API

`POST /api/catalog/import`
Headers:
  - `Authorization: Bearer <CATALOG_IMPORT_TOKEN>`
  - `Content-Type: application/json; charset=utf-8`

Body:
```json
{
  "mode": "full",
  "source": "office-pc-01",
  "items": [
    {
      "sku":                 "M15862",
      "name":                "КВШ лебедки ZIEHL-ABEGG ...",
      "name_en":             "Traction Sheave for ...",

      "brands_raw":          "ZIEHL-ABEGG;KLEEMANN;Мой ЗиП",
      "articles_raw":        ";6F31-04-12018;M15862",
      "units_raw":           "Главный привод, лебёдка лифта, ...",

      "placement":           "Лифт",
      "part_type":           "Канатоведуший шкив (КВШ)",
      "form_factor":         "8",

      "sizes_raw":           "A=240;B=11;C=6.5",
      "weight":              0,

      "price":               261722.50,
      "price_min":           256488.05,
      "is_price_actual_raw": "Нет",

      "stock_available":     0,
      "lead_time_days":      70,
      "photo_url":           "https://mylift.ru/photo.php?id=...",
      "description":         "...",
      "comment":             "..."
    }
  ]
}
```

Required: `sku`, `name`. Остальные nullable.

**Мульти-поля** (`brands_raw`/`articles_raw`/`units_raw`) — `;`-разделённые строки
из MDB. `brands_raw` и `articles_raw` выровнены 1:1 по индексу (пустой слот
обозначает «у этого бренда нашего соответствия артикула нет»). Альтернатива
для JSON-клиентов — передать готовые массивы `brands`/`articles`/`units`.

**`sizes_raw`** — формат `A=240;B=11;C=6.5` (как в MDB-поле «Размеры»);
`-` или пусто означает «размер не указан». Парсится в колонки `size_a..size_f`.

**`is_price_actual_raw`** — «Да»/«Нет» из MDB-поля «Актуальность». Семантика:
можно ли транслировать цену клиенту без запроса поставщику. По умолчанию
`true`, если поле отсутствует.

**Скалярные `brand`/`brand_article`** — НЕ передавайте напрямую. Сервис
сам выбирает primary OEM из мульти-списков (первая не-«Мой ЗиП» пара),
чтобы матчинг по артикулу против запросов клиентов попадал в OEM-артикул.

**Поля MDB «Ссылка» и «CRC»** — сознательно игнорируются (нет потребителя).

Response 200:
```json
{
  "import_id": 42,
  "mode": "full",
  "rows_total": 8327,
  "rows_created": 12,
  "rows_updated": 34,
  "rows_unchanged": 8270,
  "rows_soft_deleted": 11,
  "duration_ms": 2870,
  "errors": []
}
```

## Защита от случайного обнуления каталога

`mode=full` snapshot означает «вот ВСЕ строки каталога сейчас», и
всё, чего нет в payload'е, помечается `is_active=false` (soft-delete).
Битая выгрузка с парой строк бы схлопнула 99% каталога. Защита:

```env
# .env на проде
CATALOG_IMPORT_MIN_FULL_ROWS=500
```

С этим порогом запросы с `items` < 500 получают `422 snapshot_too_small`
ещё до записи в БД. После первой нормальной выгрузки подбери значение
~80% от ожидаемого размера. Дефолт `1` — запрет только пустого payload'а.

## Откатиться / выключить

На проде:
```env
CATALOG_IMPORT_TOKEN=
```
Endpoint начнёт отдавать `503 catalog_import_disabled`. Перезапуск
скриптов не нужен — следующий cron-вызов получит ошибку и Task Scheduler
отметит её.

## Просмотр истории импортов

```bash
php artisan tinker --execute='\App\Models\CatalogImport::latest()->limit(20)->get()->each(fn($i)=>print_r($i->only(["id","source","rows_total","rows_created","rows_updated","rows_unchanged","rows_soft_deleted","duration_ms","created_at"])));'
```
