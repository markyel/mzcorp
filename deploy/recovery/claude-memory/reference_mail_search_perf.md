---
name: reference_mail_search_perf
description: "Поиск в разделе «Почта» (/dashboard/mail): trgm-индексы + body_plain из html; было 15-20с"
metadata:
  node_type: memory
  type: reference
---

Раздел «Почта» тормозил 15-20с на поиске И просмотре письма (2026-08-19). Причины: (1) `Mail\Index::buildQuery` искал `ILIKE '%...%'` по `body_plain`+`body_html` (906МБ html) = seq-scan всех тел (≈5с); (2) `emails()`/`expandedEmail()` — `#[Computed]` (кэш за 1 рендер, ок), но пере-выполняются на КАЖДЫЙ Livewire-update (раскрытие/пагинация) → каждый клик платит стоимость поиска.

**Решение (НЕ урезать поиск по содержимому — это ключевая функция раздела):**
- **trgm GIN-индексы** (pg_trgm) на `from_email`, `from_name`, `subject`, `body_plain`, `(to_recipients::text)`, `(cc_recipients::text)` — миграции 2026_08_19_1400/1500/1600, все `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, `$withinTransaction=false`. ГЛАВНОЕ: если хоть одна ветка OR не индексирована (jsonb→text to/cc), планировщик роняет ВЕСЬ OR в seq-scan → индексировать НАДО все searchable-колонки, включая `(to_recipients::text)`.
- **body_html НЕ ищем/индексируем** (906МБ, дубль). Вместо этого `body_plain` заполняется html→text для html-only писем (было 18% с пустым body_plain): `MessagePersister::bodyPlainWithHtmlFallback` (EmailTextCleanerService::htmlToText, fail-soft strip_tags) для новых + бэкфилл существующих 14061 (chunkById, htmlToText). Закрывает и старую граблю «MessagePersister пишет body_plain=getTextBody() без html-fallback».
- Индекс body_plain ~101МБ, строится ~50с на 79k строк.

Итог: реальные слова (артикул/название) ~0.03-0.16с; сверх-частая подстрока «@mail.ru» (10к совпадений) ~1с (предел substring-поиска — много recheck). Было 15-20с. commit'ы b213c43/c438b4d/39b42a5. Связано: [[email-storage-pruning]] (raw_source раздул БД до 16ГБ).
