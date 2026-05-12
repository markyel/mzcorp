#!/usr/bin/env python3
"""
Экспорт корпоративного каталога из MS Access (.mdb) и push на
mzcorp.ru (POST /api/catalog/import).

Запускается из Windows Task Scheduler на офисной машине.

Требования:
  - Python 3.10+
  - pip install pyodbc requests
  - Microsoft Access Database Engine 2016 Redistributable
    https://www.microsoft.com/en-us/download/details.aspx?id=54920
    (нужен Access Database Engine, не обязательно сам Access)

Конфиг (env vars или CLI):
  MDB_PATH       — путь к .mdb (можно UNC: \\\\server\\share\\catalog.mdb)
  MDB_TABLE      — имя таблицы (по умолчанию "Products")
  API_URL        — https://mzcorp.ru/api/catalog/import
  API_TOKEN      — Bearer-токен (соответствует CATALOG_IMPORT_TOKEN на проде)
  SOURCE_TAG     — короткое имя машины/источника (опц.)

Маппинг MDB-полей → API:
  Артикул        → sku             (обяз.)
  Наименование   → name            (обяз.)
  НаименованиеENG→ name_en
  Узел           → unit_name
  ТипЗапчасти    → part_type
  Бренд          → brand
  БрендАртикул   → brand_article
  ФормФактор     → form_factor
  РазмерA..F     → size_a..size_f
  Вес            → weight
  Цена           → price
  ЗапасыНаСкладе...→ stock_available

Поля ЦенаLiftway, ВРаспродаже, Ссылка, Фото, Поле1 — игнорируются
(не используются на проде в Phase 2).

Выходной формат:
  {
    "mode": "full",
    "source": "<SOURCE_TAG>",
    "items": [ {sku:..., name:..., ...}, ... ]
  }

Логирует stdout: status_code + response body. Возвращает exit 0 при
2xx, 1 — при сетевом/HTTP-фейле. Task Scheduler можно настроить на
уведомление при exit != 0.
"""

from __future__ import annotations

import json
import os
import sys
from decimal import Decimal

try:
    import pyodbc
except ImportError:
    sys.stderr.write("ERROR: установите pyodbc: pip install pyodbc\n")
    sys.exit(2)

try:
    import requests
except ImportError:
    sys.stderr.write("ERROR: установите requests: pip install requests\n")
    sys.exit(2)


# --- Конфиг -----------------------------------------------------------

MDB_PATH = os.environ.get("MDB_PATH", r"C:\catalog\catalog.mdb")
MDB_TABLE = os.environ.get("MDB_TABLE", "Products")
API_URL = os.environ.get("API_URL", "https://mzcorp.ru/api/catalog/import")
API_TOKEN = os.environ.get("API_TOKEN", "")
SOURCE_TAG = os.environ.get("SOURCE_TAG", os.environ.get("COMPUTERNAME", "office-pc"))

# --- Маппинг ---------------------------------------------------------

COLUMN_MAPPING = {
    "Артикул":           "sku",
    "Наименование":      "name",
    "НаименованиеENG":   "name_en",
    "Узел":              "unit_name",
    "ТипЗапчасти":       "part_type",
    "Бренд":             "brand",
    "БрендАртикул":      "brand_article",
    "ФормФактор":        "form_factor",
    "РазмерA":           "size_a",
    "РазмерB":           "size_b",
    "РазмерC":           "size_c",
    "РазмерD":           "size_d",
    "РазмерE":           "size_e",
    "РазмерF":           "size_f",
    "Вес":               "weight",
    "Цена":              "price",
    # Полное имя в MDB может быть длиннее (truncate видно в схеме).
    # Подкрути под реальное имя своей колонки.
    "ЗапасыНаСкладеСвободныйОстаток": "stock_available",
}


def normalize_value(value):
    if value is None:
        return None
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, (bytes, bytearray)):
        try:
            return value.decode("utf-8", errors="replace")
        except Exception:
            return None
    return value


def fetch_rows() -> list[dict]:
    conn_str = (
        r"Driver={Microsoft Access Driver (*.mdb, *.accdb)};"
        f"DBQ={MDB_PATH};"
    )
    rows: list[dict] = []
    try:
        with pyodbc.connect(conn_str, autocommit=True) as conn:
            cur = conn.cursor()
            mdb_cols = list(COLUMN_MAPPING.keys())
            select_cols = ", ".join(f"[{c}]" for c in mdb_cols)
            cur.execute(f"SELECT {select_cols} FROM [{MDB_TABLE}]")
            cols = [d[0] for d in cur.description]
            for row in cur.fetchall():
                api_row: dict = {}
                for mdb_col, value in zip(cols, row):
                    api_key = COLUMN_MAPPING.get(mdb_col)
                    if api_key is None:
                        continue
                    api_row[api_key] = normalize_value(value)
                if api_row.get("sku") and api_row.get("name"):
                    rows.append(api_row)
    except pyodbc.Error as e:
        sys.stderr.write(f"ERROR: pyodbc — {e}\n")
        raise
    return rows


def push(rows: list[dict]) -> int:
    if not API_TOKEN:
        sys.stderr.write("ERROR: API_TOKEN не задан\n")
        return 1
    payload = {"mode": "full", "source": SOURCE_TAG, "items": rows}
    print(f"POST {API_URL}  ({len(rows)} rows, source={SOURCE_TAG})")
    try:
        resp = requests.post(
            API_URL,
            data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "Authorization": f"Bearer {API_TOKEN}",
                "Accept": "application/json",
            },
            timeout=180,
        )
    except requests.RequestException as e:
        sys.stderr.write(f"ERROR: network — {e}\n")
        return 1
    print(f"HTTP {resp.status_code}")
    print(resp.text[:4000])
    return 0 if 200 <= resp.status_code < 300 else 1


def main() -> int:
    rows = fetch_rows()
    if not rows:
        sys.stderr.write("WARNING: 0 rows из MDB — push отменён (защита от пустого snapshot'а)\n")
        return 1
    return push(rows)


if __name__ == "__main__":
    sys.exit(main())
