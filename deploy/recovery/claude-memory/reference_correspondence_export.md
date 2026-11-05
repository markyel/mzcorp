---
name: correspondence-export
description: "Экспорт переписки заявки в PDF/ZIP + грабли рендеринга email-HTML в dompdf (кириллица, удалённые картинки, размеры)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Экспорт переписки заявки в PDF (или ZIP при наличии файлов-вложений). Кнопка «⭳ Экспорт в PDF» в табе «Переписка» карточки заявки + режим «Выбрать письма…» (Alpine-чекбоксы, без Livewire-раундтрипов, URL `?messages=1,2,3`).

- **Файлы:** `App\Services\Requests\CorrespondenceExportService` (сборка треда + dompdf-рендер + ресайз картинок), `App\Http\Controllers\CorrespondencePdfController` (решение PDF vs ZIP + permission owner/acting/privileged), шаблон `resources/views/requests/correspondence-pdf.blade.php`, роут `requests.correspondence.export`, UI в `resources/views/livewire/requests/detail.blade.php` (@case('thread')).
- **Логика:** тред = та же выборка что в `Requests\Detail` (related_request_id, без cross-mailbox копий, **без черновиков**). Картинки (inline cid: + фото-вложения) встраиваются в PDF как data-URI; прочие файлы (PDF/Excel/doc) → в ZIP рядом с PDF (имена префиксуются `01_`). Нет файлов → один PDF. Рендер/шрифты PT Sans копируют `QuotationPdfService`.

**Грабли рендеринга произвольного email-HTML в dompdf (все всплыли на M-2026-5649):**
1. **Кириллица → «???»**: подписи почтовых клиентов несут свой `font-family` (Helvetica/Arial) → dompdf берёт встроенный Type1-шрифт БЕЗ кириллицы. Фикс: снять `font-family` из inline-style и `<font face>` + форсировать `font-family:'PT Sans' !important` на `.msg-body *`.
2. **Крупный шрифт / наезд строк**: подписи задают размер/межстрочный через `font:`-shorthand (15-17px). Фикс: `font-size`/`line-height` `!important`-longhand в шаблоне (перекрывает shorthand из inline-style).
3. **Наложение текста (главное!)**: логотип подписи — **удалённая** картинка (`mzcorp.ru/assets/logo-myzip-email.png`), а `isRemoteEnabled=false` → dompdf рисует её `alt`-текст, который вылезает из ячейки и наезжает на соседний. Фикс: `resolveRemoteImages()` — картинки с наших доменов (mzcorp/myzip/mylift) инлайнить из `public/`, чужие удалённые вырезать. **isRemoteEnabled оставлен false** намеренно (SSRF/трекинг-пиксели из писем клиентов).
4. **page-break-inside:avoid** на высоких блоках (.msg) тоже давал наложения — убрал.
5. **Цитаты не экспортируем** (только сами письма): `stripQuotedBlocks()` (HTML, DOM — вырезает blockquote/gmail_quote/yahoo_quoted + attribution-строки перед цитатой) + `stripQuotedPlain()` (plain). Грабля: attribution детектится по глаголу — нужны и «написал(а):» (Apple/Yandex), и «пишет:» (Mail.ru), и текстовый месяц с точкой («23 июн. 2026 … написал»), иначе строка-«шапка» остаётся сиротой над удалённой цитатой. Проверка: `pdftotext | grep -iE "написал|пишет|wrote|writes"` → 0.
6. **Zero-width «тофу»**: dompdf рисует notdef-боксы для невидимых символов (U+200B ZWSP, ZWNJ/ZWJ, BOM, word-joiner, LRM/RLM, soft-hyphen) — `stripInvisibleChars()` их вырезает. Кейс M-2026-5290: клиент вставил 7× U+200B перед номерами пунктов списка → «XXXXXXX» вместо «4. Канат».
7. **Ресайз фото**: НЕ переиспользовать `VisionImageDownscaler` (его MAX_EDGE=2048 «под OpenAI»). Своя `imageDataUri()` ужимает до ~900px/JPEG q78 (на странице фото ~150px) — иначе PDF из фото с телефона раздут (было 1.8МБ → 0.6МБ). Fail-soft на отсутствие GD.

**Проверка PDF на проде:** рендер `sudo -u www-data php artisan tinker <script.php>` (НЕ под root — иначе dompdf font-cache станет root-owned, см. [[prod-deploy]]); `pdftotext` для текста, `pdftoppm -png` → scp локально → визуально. Деплой обычный [[prod-deploy]] (роут менялся раз → нужен route:cache; CSS в dompdf-шаблоне — не Tailwind, npm build не нужен).
