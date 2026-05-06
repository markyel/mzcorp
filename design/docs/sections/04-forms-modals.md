# 04 · Forms & Modals

> Любой ввод пользователя: создать заявку, назначить менеджера, добавить позицию, настроить sticky-правило.

## Когда применять

- «модалка», «диалог», «попап»
- «форма создания», «новый клиент», «добавить артикул»
- «бок-панель / drawer для редактирования»
- «инлайн-форма внутри карточки»

Три формата — выбирай по правилам ниже:

| Формат | Когда |
|---|---|
| **Inline** в карточке | редактирование одного-двух полей текущего объекта |
| **Drawer** (right, 480px) | создание/редактирование объекта без потери контекста списка |
| **Modal** (центрированная) | подтверждения, deconstructive actions, мульти-шаговые мастера |

Если сомневаешься — drawer. Modal — последняя инстанция, она прерывает работу.

## Anatomy · Modal

```
┌─────────────────────────────────────────────────────────┐
│ HEADER · 56px                                           │
│  h2 16px semibold                              [×]      │
│  meta-line 12.5px fg-3 (опц.)                           │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ BODY · 24px padding · max 480px width                   │
│   form rows…                                            │
│                                                         │
├─────────────────────────────────────────────────────────┤
│ FOOTER · 64px                                           │
│  [secondary]                       [Отмена] [Primary]   │
└─────────────────────────────────────────────────────────┘
```

- Modal: 480–560px width, max-height 80vh, body scrollable.
- Border-radius `var(--r-lg)` (10px), shadow `var(--shadow-lg)`, border 1px.
- Backdrop: `rgba(15,20,25,0.5)` (не чёрный).
- Padding header/footer 20px x · 16px y. Body 24px.

## Anatomy · Drawer

```
                     ┌───────────────────────────┐
                     │ HEADER · 56px             │
                     │  back ← · h2 ·       [×] │
                     ├───────────────────────────┤
                     │ TABS (опц.) · 36px        │
                     ├───────────────────────────┤
                     │ BODY · 20px padding       │
                     │   form sections…          │
                     │   (scroll)                │
                     ├───────────────────────────┤
                     │ FOOTER STICKY · 64px      │
                     │       [Отмена] [Primary]  │
                     └───────────────────────────┘
```

- Width 480px (compact 400, comfortable 560).
- Slide-in 180ms cubic-bezier(0.2,0,0,1), backdrop opacity 0 → 0.4.
- Footer sticky с `border-top: 1px solid var(--border)`, `background: var(--bg-surface-2)`.
- Esc — закрывает с confirm если форма dirty.

## Form rows

```
┌─────────────────────────────────────────────────────────┐
│ LABEL · 12.5px medium fg-2  · опционально help-tooltip │
│ ┌───────────────────────────────────────────────────┐  │
│ │ INPUT · 36px · 13px · 1px border var(--border)   │  │
│ └───────────────────────────────────────────────────┘  │
│ help / error · 11.5px · fg-3 / accent                  │
└─────────────────────────────────────────────────────────┘
```

- Row gap 14px (16px max).
- Label сверху, не сбоку (responsive, читается одинаково).
- Required — `*` после label `--accent`. Не «обязательное поле» текстом.
- Help — серый 11.5px fg-3 под полем, **только если есть что объяснить**. Не «Введите имя клиента» под полем «Имя клиента».
- Error — `--accent` text + `border-color: var(--accent)` + `box-shadow: 0 0 0 3px rgba(193,21,46,0.12)`. Иконку слева от текста — нет.

### Размеры инпутов

| Тип | Высота | Когда |
|---|---|---|
| **default** | 36px | большинство |
| **comfortable** | 40px | первая форма онбординга, мобила |
| **compact** | 28px | inline-edit в таблице |

### Типы полей · правила

- **Text** — `padding: 8px 12px;`. Placeholder — fg-3, italic нет, не подсказка-инструкция (`Иванов А.И.` — да; `Введите ФИО` — нет).
- **Number / money** — text-align right в поле, suffix (`₽`) в `addon` справа `--bg-surface-2`. Tabular-nums.
- **Date** — `chip-style picker`, не нативный input. Формат `пт 22 ноя 2025` или `22.11.2025` mono.
- **Select** — кастомный, listbox 36px rows, max-height 320, search автомат при > 8 опций.
- **Combobox / search-select** — для клиента, поставщика, артикула. Внутри listbox — t1+t2 (имя + ИНН/код).
- **Tags** — chip-input, новый chip по `,` или Enter. Удаление backspace.
- **Textarea** — min-height 80, resize vertical, 13px regular.
- **Checkbox** — 14×14, border 1.5px `--border-strong`, checked → `--accent` fill + white check. Не круглый.
- **Radio** — 14×14 круг, dot 6×6 центр.
- **Toggle (switch)** — 28×16, off `--border-strong`, on `--accent`. Только для on/off, не для двойного выбора.
- **Segmented control** — 28px высоты, 4–8px x-pad, max 4 пункта. Active — `bg-surface-2` + 1px border + `inset 0 -2px 0 var(--accent)`.

## Section / fieldset внутри формы

Если формa длиннее 6 полей — делим на fieldset-карточки:

```
┌──────────────────────────────────────────┐
│ ЗАЯВКА · uppercase 11.5px fg-3 · 16px y  │
├──────────────────────────────────────────┤
│ row · row · row …                        │
└──────────────────────────────────────────┘
```

Между fieldset 16px gap. Fieldset — 1px border `--border-subtle`, padding 16px.

## Footer / actions

- Primary справа, secondary слева от primary. Пример: `[Отмена] [Создать заявку]`.
- **Никогда** не обратный порядок (cancel справа).
- Destructive — отдельный red ghost слева, `[Удалить навсегда]                  [Отмена] [Сохранить]`.
- В мультистаге — слева `← Назад`, справа `Далее →`. Финальный шаг — `Создать`.
- Loading — primary показывает spinner 12px + текст без изменения ширины (фикс ширина по самому длинному).

## Multi-step (мастер)

Стейп-индикатор сверху body (не в header):

```
●───●───○───○        Шаг 2 из 4 · «Поставщики»
```

- Точка 8px, line 1px. Пройденные `--emerald-500`, текущая `--accent`, будущие `--border-strong`.
- Не показывай step labels на самой шкале — только под, типографика 11.5px medium.
- Не более 5 шагов. 6+ — это уже не мастер, это форма-страница.

## Copywriting

- **H2 модалки** — действие + объект: `Создать заявку`, `Назначить менеджера`, не `Новая заявка`.
- **Submit button** — повторяет h2: `[Создать заявку]`. Не `[Сохранить]` без объекта.
- **Confirm destructive** — глагол + объект в кавычках:
  - `Удалить заявку «Замок ДШ Metron 2PTL»?`
  - body: `Восстановить нельзя. Все вложения и переписка останутся в архиве.`
  - actions: `[Отмена] [Удалить навсегда]` (red)
- **Validation** — конкретно, без «введите корректное значение»:
  - `ИНН должен быть 10 или 12 цифр.`
  - `Дата отгрузки не может быть раньше сегодня.`
- **Empty combobox** — `Не нашли. [Создать «Иванов А.И.» как нового клиента]` (link inline).
- **Toast после submit** — факт + объект: `Заявка MZ-21384 создана.` Не `Успешно!`.

## Do / Don't

| ✅ Do | ❌ Don't |
|---|---|
| Drawer для CRUD без потери контекста | Модалка для каждого create |
| 1 primary в footer (red) | 2 primary («Сохранить» + «Сохранить и закрыть») |
| Label сверху | Label слева 120px (ломается responsive) |
| Required `*` color accent | «(обязательное)» текстом |
| Submit повторяет h2 | Submit `[OK]` или `[Сохранить]` |
| Esc → confirm если dirty | Esc → молча выкидывает данные |
| Destructive button — отдельный red ghost | Destructive в primary позиции справа |

## Edge cases

- **Долгая операция (> 1 c)** — primary становится `[Создаём… ▢]`, форма disabled но не закрыта. Если > 5 c — показать inline-progress `Шаг 2 из 4: проверяем поставщиков`.
- **Network fail** — inline-баннер сверху body `Не удалось сохранить. [Повторить]`. Не модалка-в-модалке.
- **Конфликт версий** — баннер `Запись изменена другим пользователем. [Загрузить актуальную]`. Поля помечаются `border-color: var(--amber-500)`.
- **Незаполненные required при submit** — фокус первому, страница скроллит к нему, error под полем. Не общее `Заполните все поля`.
- **Уход со страницы при dirty** — `confirm()` через `beforeunload` если drawer/modal, через router-prompt если inline.
- **Длинный select** — virtualized list. Поиск автомат при > 8 опциях.
- **Mobile** — drawer становится bottom-sheet, modal — full-screen.

## Reference

- Готовая модалка-конфирм: см. блок `Modal · destructive` в [`design_system_preview/04-components.html`](../../design_system_preview/04-components.html)
- Drawer пример: см. inspector в [`ui_kits/crm/01-pool.html`](../../ui_kits/crm/01-pool.html)
- Живой preview: [`docs/preview/section-forms.html`](../preview/section-forms.html)

## Checklist

- [ ] Inline / Drawer / Modal — выбран по правилу из таблицы выше
- [ ] Label сверху, required = красная `*`, help только если объясняет
- [ ] 1 primary в footer, повторяет h2
- [ ] Destructive — отдельный red ghost слева
- [ ] Esc + click backdrop → confirm если dirty
- [ ] Validation — конкретные сообщения с фокусом первого поля
- [ ] Loading state primary — `[Создаём…]` с фиксированной шириной
- [ ] Empty combobox — inline-link «Создать новый»
