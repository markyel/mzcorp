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
      "sku":              "M02016",
      "name":             "Контактор Siemens 3RT2016-2GG22",
      "name_en":          null,
      "unit_name":        "Привод дверей",
      "part_type":        "Контактор",
      "brand":            "Siemens",
      "brand_article":    "3RT2016-2GG22",
      "form_factor":      null,
      "size_a":           45.0,
      "size_b":           90.0,
      "size_c":           null,
      "size_d":           null,
      "size_e":           null,
      "size_f":           null,
      "weight":           0.35,
      "price":            1234.50,
      "stock_available":  5
    }
  ]
}
```

Required: `sku`, `name`. Остальные nullable.

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
