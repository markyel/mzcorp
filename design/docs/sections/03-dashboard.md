# 03 · Dashboard / KPI / отчёт

> Аналитические страницы: главный дашборд, отчёты по менеджерам, по поставщикам, по бренду, недельная сводка.

## Когда применять

- «дашборд», «KPI», «главная страница руководителя»
- «отчёт по менеджерам / поставщикам / выручке»
- «недельная сводка», «воронка»

Если страница — это просто **большой KPI-блок над списком** (например, шапка над списком заявок), это **page header**, а не dashboard. См. [01-list.md](01-list.md).

## Anatomy

```
┌────────────────────────────────────────────────────────────────────┐
│ TOPBAR                                                              │
├────┬───────────────────────────────────────────────────────────────┤
│    │ HEADER · h1 · period selector · refreshed · export             │
│    │ ┌─────────── KPI ROW · 4–6 stat cards · 1fr each ───────────┐ │
│ R  │ │ value 28px tabular · delta · spark 80×24 · footnote      │ │
│    │ └────────────────────────────────────────────────────────────┘│
│ A  │ ┌─ MAIN GRID ───────────────────────────────────────────────┐ │
│    │ │ 12-col, gap 16px                                          │ │
│ I  │ │ chart card (col-span 8) · breakdown card (col-span 4)     │ │
│    │ │ table card (col-span 12) — топ-N с барами                │ │
│ L  │ │ heatmap card (col-span 6) · funnel card (col-span 6)      │ │
│    │ └────────────────────────────────────────────────────────────┘│
│    │ FOOTER · источник данных · версия отчёта · timezone           │
└────┴───────────────────────────────────────────────────────────────┘
```

## Слои сверху вниз

### 1 · Header (60px)
- H1 — `Дашборд · оперативная` / `Отчёт по менеджерам · нед. 47`. После `·` — точное имя периода.
- Справа: **period selector** (seg-control: `день · неделя · месяц · квартал · custom`), **refreshed at HH:MM** mono fg-3, кнопка **`Экспорт PDF / CSV`**.
- Период всегда читаем словом, не датами в селекторе. Полный диапазон — в meta под h1: `пн 18 ноя – вс 24 ноя · 7 рабочих дней`.

### 2 · KPI row (4–6 cards)

Каждая карточка KPI:

```
┌─────────────────────────────────────┐
│ LABEL · 11.5px uppercase fg-3       │
│                                     │
│ 142 800 ₽       ↑ +12,4 %  vs пред  │
│ 28px tnum semi   delta-pill         │
│                                     │
│ ░░▒▒██▒▒░░  spark 80×24             │
│ 7 дн                                │
└─────────────────────────────────────┘
```

- Card padding 16px, 1px border, NO shadow.
- **Value** — 28px sans-semibold, `font-feature-settings: 'tnum'`. Деньги — с `₽` (неразрывный пробел перед).
- **Delta-pill** — 12px medium с стрелкой:
  - up + good → `--emerald-700` text, `--emerald-50` bg
  - up + bad (e.g. возраст заявок) → `--red-700` text, `--red-50` bg
  - down + good → emerald
  - down + bad → red
  - neutral → fg-3, без bg
- **Spark** — 80×24 inline SVG, line или bars. Цвет линии — fg-2 (графики не должны кричать).
- **Footnote** — `за 7 дн` / `vs пред. неделя` 11px fg-3.

Минимум 4, максимум 6 KPI. Если хочется 7+ — это уже не дашборд, разбивай на табы.

### 3 · Main grid (12 cols, gap 16px)

Каждая карточка — section card как в [02-detail.md](02-detail.md):
- 44px header: `h2 14px semibold` + meta fg-3 + `···` меню
- Body — chart / table / list / heatmap

#### Типичные блоки

- **Chart card** (col-span 8): line / bar / stacked area. Высота 280px. Tooltip на hover. Легенда — chip-стиль внизу. Без 3D, без градиентной заливки.
- **Breakdown** (col-span 4): horizontal bars top-N (поставщики, бренды, менеджеры). Bar — fg-2, число справа mono.
- **Table card** (col-span 12 или 8): топ-N с inline-барами в колонке `доля` (см. ниже).
- **Heatmap** (col-span 6): сетка 7×24 (день × час) для активности; или 12×N (месяц × менеджер) для выработки. Шкала — 5 ступеней `--bg-surface-2` → `--sky-100` → `--sky-300` → `--sky-500` → `--sky-700`.
- **Funnel** (col-span 6): 5 ступеней пайплайна, каждая — bar с двумя числами (входящие · конверсия %).

#### Inline-бары в таблице

Колонка `доля` или `прогресс` — фон-bar внутри ячейки:
```html
<td class="bar"><span style="width:67%"></span><b>67%</b></td>
```
- bar fill: `var(--sky-200)`, height 100% ячейки, opacity 0.6.
- Число — поверх mono 12px tnum.

### 4 · Footer
30px fg-3 11.5px, разделители `·`:
```
источник: postgres prod · отчёт v 4.12 · TZ: Europe/Moscow · ничего не кэшировано
```

## Плотность

| Variant | Когда | KPI cards | Grid gap |
|---|---|---|---|
| **comfortable** | руководителю на ноуте | 4 | 20px |
| **default** | прод дашборд | 5–6 | 16px |
| **compact** | TV-режим / дашборд на стене | 6+ | 12px, value 36px |

## Copywriting

- **KPI label** — uppercase, без артиклей: `ВЫРУЧКА В РАБОТЕ`, не `Текущая выручка`.
- **Footnote** — фактический диапазон: `за 7 дн`, `vs пред. неделя`, `с 1 ноя`. Не `last week`, не `WoW`.
- **Empty chart** — `Нет данных за выбранный период.` — одна строка по центру card body.
- **Loading chart** — skeleton-блок размера chart, не spinner.
- **Tooltip** — `пт 22 ноя · 142 800 ₽ · 12 заявок` (день mono · сумма · количество).

## Do / Don't

| ✅ Do | ❌ Don't |
|---|---|
| KPI value 28px tnum semibold | KPI value 14px regular |
| Delta — pill с цветом по «хорошо/плохо» | Delta всегда зелёным/красным по знаку |
| Spark fg-2 линия 1.5px | Spark с градиентом fill |
| Chart легенда в виде chip-ов снизу | Легенда сбоку с цветными квадратиками |
| Heatmap — sky шкала из 5 ступеней | Heatmap — радужный градиент |
| Period selector — словами | Period selector — два date-picker |

## Edge cases

- **Нет данных за период** — KPI value `—` mono fg-3, без delta, без spark. Footnote `недостаточно данных`.
- **Прошлый период не покрыт** — delta скрывается совсем, не показывается `vs n/a`.
- **Очень большое число** — компактный формат: `4,12 М ₽` (`М` после числа, без тысячных). Полное число в tooltip card hover.
- **Менеджер уволен** — в breakdown показывается с пометкой `(архив)` fg-3 после имени.
- **Outlier в spark** — не клипуй, дай spark расти за пределы baseline; добавь note в footnote `включает outlier 24 ноя`.
- **Realtime обновление** — refreshed at тикает раз в 60с; KPI value flash-анимирует через 120ms transition `background: var(--sky-50)` → null.

## Reference

- Готовый экран: [`ui_kits/crm/02-dashboard.html`](../../ui_kits/crm/02-dashboard.html)
- Живой preview: [`docs/preview/section-dashboard.html`](../preview/section-dashboard.html)

## Checklist

- [ ] H1 содержит точное имя периода (`нед. 47`, не `эта неделя`)
- [ ] 4–6 KPI cards, value 28px tnum, есть delta + spark
- [ ] Delta-pill цветом по «хорошо/плохо», не по знаку
- [ ] Минимум 1 chart, 1 breakdown/таблица, 1 heatmap или funnel
- [ ] Нет градиентов в chart fill
- [ ] Footer содержит источник и timezone
- [ ] Loading state — skeleton, не spinner
- [ ] `Экспорт PDF` — secondary, не primary
