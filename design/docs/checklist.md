# Checklist · перед сдачей экрана

> Прогон 30 секунд. Если хотя бы одна галочка не стоит — экран не готов.

Используй этот файл **перед** тем, как написать пользователю «готово». Прочекай поэлементно, не «на глаз».

## A · Подключение системы

- [ ] `colors_and_type.css` подключён относительным путём (`../colors_and_type.css` или `../../colors_and_type.css`)
- [ ] Никаких хардкод-цветов внутри `<style>` блока экрана. Только `var(--…)`.
- [ ] Никаких хардкод-шрифтов. Только `var(--font-sans)` / `var(--font-mono)`.
- [ ] `lang="ru"` на `<html>`.
- [ ] Заголовок документа осмысленный, не `Document` / `Untitled`.

## B · Цвет

- [ ] **Бренд-красный** (`var(--accent)`) — только на: primary CTA, активный пункт нав-рейла, критический badge. Нигде больше.
- [ ] Selected state — sky (`var(--bg-selected)` + `var(--sky-500)` left inset), не red.
- [ ] Focus-ring — sky, не red. `box-shadow: 0 0 0 3px var(--ring)`.
- [ ] Status chip-ы используют только закрытый набор: info-sky / over-red / attn-amber / ok-emerald / paused-fg-3 / sticky-violet.
- [ ] Нет градиентов в фоне страницы / карточек / chip-ов.
- [ ] Нет shadow-цветных (всегда `--shadow-*` из палитры).

## C · Типографика

- [ ] H1 — 20px sans-semibold (или 22 для главной), letter-spacing -0.01em.
- [ ] H2 — 14px sans-semibold с meta-row 12.5px fg-3.
- [ ] Body — 13px regular fg-1, line-height 1.5.
- [ ] Текст в chip-ах — 11.5–12.5px medium, lowercase (`на согласовании`, не `На согласовании`).
- [ ] **Mono** — для ID, кодов, артикулов, timestamps, message-id, sums где нужно тнум-выравнивание. Нигде больше.
- [ ] Числа — `font-feature-settings: 'tnum'` на всех колонках, где есть денежные / количественные значения.
- [ ] Деньги — формат `142 800 ₽` (неразрывный пробел перед ₽). НЕ `₽142,800` и НЕ `142800₽`.
- [ ] Даты — короткий формат `пт 22 ноя` или `22.11.2025` mono. В формальных документах — `22 ноября 2025 г.`.

## D · Плотность

- [ ] Строка таблицы — 36px (default) или 28px (compact). НЕ 48+.
- [ ] Padding ячейки таблицы — 12px по горизонтали, 8px (default) или 4px (compact) по вертикали.
- [ ] Padding карточки — 16–20px. НЕ 24+ (это data UI, не лендинг).
- [ ] Section card-ы между собой — 16px gap. НЕ 24+.
- [ ] Inputs — 36px (default), 28px (compact, inline-edit). НЕ 44+.
- [ ] Form rows gap — 14px. НЕ 24+.

## E · Структура экрана (по типу)

### Если list — пройди [01-list.md#checklist](sections/01-list.md#checklist--перед-сдачей-экрана-списка)

- [ ] Topbar и rail из `01-pool.html` без изменений
- [ ] Context list 240px с группами (минимум `Мои` + `Команда`)
- [ ] H1 содержит число `· N`
- [ ] Bulk bar появляется только при `selectedCount > 0`
- [ ] Один accent CTA в page header

### Если detail — пройди [02-detail.md#checklist](sections/02-detail.md#checklist)

- [ ] Sticky header с кодом (mono) + h1 + chip status + возраст
- [ ] Не более 1 primary (red) кнопки в action row
- [ ] Tabs с counts mono
- [ ] Aside содержит минимум 2 из 3: Workflow / Properties / Activity

### Если dashboard — пройди [03-dashboard.md#checklist](sections/03-dashboard.md#checklist)

- [ ] H1 содержит точное имя периода (`нед. 47`)
- [ ] 4–6 KPI cards со spark и delta
- [ ] Минимум 1 chart + 1 breakdown + 1 heatmap/funnel

### Если form / modal — пройди [04-forms-modals.md#checklist](sections/04-forms-modals.md#checklist)

- [ ] Inline / Drawer / Modal — выбран по правилу
- [ ] Label сверху, required = красная `*`
- [ ] 1 primary в footer, повторяет h2

### Если empty / loading / error — пройди [05-empty-loading-error.md#checklist](sections/05-empty-loading-error.md#checklist)

- [ ] Empty — 1 строка факта + 1 действие, без иллюстрации
- [ ] Loading — skeleton, не spinner
- [ ] Error inline — на месте контента

### Если email — пройди [06-email-templates.md#checklist](sections/06-email-templates.md#checklist)

- [ ] Table-вёрстка, inline styles, web-safe fonts
- [ ] Max width 600px

### Если onboarding — пройди [07-onboarding.md#checklist](sections/07-onboarding.md#checklist)

- [ ] Setup как page, не tour-modal
- [ ] 6 шагов из закрытого набора

### Если print — пройди [08-print.md#checklist](sections/08-print.md#checklist)

- [ ] `@page A4 + margin` задан
- [ ] Цвета только из print-палитры (без sky/emerald/amber)

### Если settings — пройди [09-settings.md#checklist](sections/09-settings.md#checklist)

- [ ] Sub-nav 240px с group-labels uppercase
- [ ] Save-bar sticky bottom только при dirty

## F · Контент

- [ ] Все тексты по-русски, sentence case (не Title Case, не ALL CAPS).
- [ ] Никаких эмодзи (ни в content, ни в empty, ни в success-toast).
- [ ] Никаких иллюстраций / картинок-героев.
- [ ] Никаких `Что-то пошло не так`, `Подождите, пожалуйста`, `Поздравляем!`, `Упс!`, `Молодец!`.
- [ ] Бренды как в каталоге (без транслита): `OTIS`, `KONE`, `ЩЛЗ`, `WITTUR`, не `Отис`.
- [ ] Статусы из закрытого набора: `получено · на квалификации · в работе · ждём клиента · refresh цен · КП отправлено · счёт выставлен · оплачено · на паузе · закрыто (не наша тема)`.
- [ ] Числа в данных — реалистичные (не `Lorem ipsum 0`, не повторяющиеся `999`). Если placeholder — пометь явно `— заполнится`.

## G · Поведение

- [ ] Hover на строке таблицы — `--bg-hover`, не border-color change.
- [ ] Selected — sky (см. B).
- [ ] Focus — sky-ring (см. B).
- [ ] Transitions — 120ms (UI), 180ms (panel slide). Easing — `cubic-bezier(0.2, 0, 0, 1)`.
- [ ] Никаких spring/bounce/elastic.
- [ ] Sticky-элементы (topbar, page header, table thead) держатся ровно, без jitter.
- [ ] Esc закрывает drawer/modal с confirm если dirty.
- [ ] `···` row menu — фиксированная 32px колонка справа, не дублируется кнопками действий в строке.

## H · Edge cases (быстрая проверка)

- [ ] Длинное имя/тема — обрезается ellipsis с tooltip.
- [ ] Отсутствие данных в поле — fg-3 dash `—`, не `null` / `undefined` / `0`.
- [ ] Просрочено — chip red + красный возраст. Без двух разных красных оттенков.
- [ ] Не привязанный клиент — `— не привязан` italic fg-3 + clickable.
- [ ] КП не сформировано — `КП не сформ.` fg-3, не `0 ₽`.
- [ ] Большие числа — `4,12 М ₽` компактный формат.

## I · Технический минимум

- [ ] Открывается без ошибок в console.
- [ ] React-compoненты импортируются через `window.X` (если несколько JSX-файлов).
- [ ] Style-объекты названы уникально (`requestListStyles`, не просто `styles`).
- [ ] CSS-переменные не переопределены локально для UI-компонентов (только для тематик).
- [ ] Картинки имеют `alt` (если есть, что описать) или `alt=""` для декоративных.
- [ ] HTML — canonical: явные закрывающие теги, double-quoted атрибуты.

## J · Предъявление

- [ ] Файл назван по-человечески (`Manager Pool.html`, не `screen-1.html`).
- [ ] Зарегистрирован как asset через `register_assets`, group выбран.
- [ ] Subtitle краткий и фактический (`Очередь распределения · 47 заявок`, не `Cool screen`).
- [ ] Если внутри есть варианты — обёрнуты в `<DCArtboard>` design canvas, не в отдельные файлы.
- [ ] Прокликан всеми interactive-элементами (hover, click, modal open/close).

---

**Если все галочки стоят** — экран сдан. Если нет — вернись к разделу выше с отметкой и почини.

См. также корневой [README.md](../README.md) для полных обоснований правил, и [SKILL.md](../SKILL.md) для инструкций как **писать** новые экраны.
