---
name: reference_quotation_delivery_line
description: "Доставка в КП (Quotation): delivery_text/term/price, прибавляется к итогу без скидки; total стал ГРАНДТОТАЛОМ (товары+доставка)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-14T11:24:16.373Z
---

Доставка в КП (commit aa79d41, 2026-08-14, запрос заказчика). На вкладке «КП» (`Quotations\Editor`) менеджер указывает доставку: **текст + срок + цена**. Поля на уровне `Quotation`: `delivery_text` (text), `delivery_term` (string, свободный — «3–5 раб. дней»), `delivery_price` (decimal 14,2, default 0). Правятся через `updateQuotationField` (добавлены в `$allowed`; при `delivery_price` — пересчёт).

**Правило: доставка прибавляется к итогу, СКИДКИ на неё НЕ распространяются. Цена НДС в т.ч.** (весь КП «вкл. НДС»).

**ВАЖНЫЙ ИНВАРИАНТ (изменилась семантика `total`):** после этой правки `QuotationService::recalcTotals` пишет `total` = **ГРАНДТОТАЛ к оплате** = товары-после-скидки + `delivery_price`. А `subtotal` и `discount_amount` остаются ТОЛЬКО по товарам. Поэтому:
- «Итого со скидкой» (товары после скидки) = `subtotal − discount_amount`, а НЕ `total`.
- «К оплате» = `total`.
- Раньше было `discount_amount = subtotal − total`; теперь `discount_amount = subtotal − goodsTotal` (goodsTotal = сумма line_total без доставки). НЕ считать скидку от `total` — там уже доставка.
- `vat_amount` = НДС товаров + НДС доставки (в т.ч.: `delivery_price − delivery_price/(1+vat/100)`).

Потребители `total` (email {total}, авто-Invoice, аналитика) получают грандтотал — это корректно (сумма к оплате). PDF (`quotations/pdf-template`): строка «Доставка (срок): +цена» в итогах + блок «Доставка: текст · срок · цена» под суммой прописью. Редактор (`editor.blade`): поля ввода в левой колонке под комментарием; в панели итогов строки «Итого со скидкой» (= subtotal−discount), «Доставка +N», «К оплате» (= total).

Проверено на проде (заявка M-2026-12138, откат-транзакция): subtotal 12428.58, скидка 10% → 11185.72, +доставка 1500 → total 12685.72 (OK, доставка не скидывается); PDF-текст (smalot) содержит блок доставки. Связано: [[reference_quotation_delivery_term]] (это ПРО ДРУГОЕ — per-item срок наличие/под-заказ), [[reference_send_client_document]].
