# MyLift CRM · Section Guide

> Документация для агента. Когда тебе говорят «сделай экран X в MyLift CRM», читай нужный раздел отсюда **до** того, как пишешь код.

## Когда что читать

| Запрос пользователя | Открой этот файл |
|---|---|
| «список заявок / клиентов / поставщиков» | [`sections/01-list.md`](sections/01-list.md) |
| «карточка заявки / клиента / письма» | [`sections/02-detail.md`](sections/02-detail.md) |
| «дашборд / KPI / отчёт» | [`sections/03-dashboard.md`](sections/03-dashboard.md) |
| «модалка / форма / создать запись» | [`sections/04-forms-modals.md`](sections/04-forms-modals.md) |
| «пустое состояние / загрузка / ошибка» | [`sections/05-empty-loading-error.md`](sections/05-empty-loading-error.md) |
| «шаблон письма / переписка» | [`sections/06-email-templates.md`](sections/06-email-templates.md) |
| «первый запуск / онбординг» | [`sections/07-onboarding.md`](sections/07-onboarding.md) |
| «печать КП / счёта / акта» | [`sections/08-print.md`](sections/08-print.md) |
| «настройки / админка» | [`sections/09-settings.md`](sections/09-settings.md) |
| перед сдачей экрана | [`checklist.md`](checklist.md) |

## Хард-правила (не нарушать никогда)

Это краткая выжимка из корневого `README.md`. Полное обоснование — там.

1. **Подключай `colors_and_type.css`** относительным путём — никаких хардкод цветов и шрифтов.
2. **Бренд-красный (`var(--accent)`) — только** на primary CTA, активном индикаторе нав-рейла, критическом badge. Никогда — фон страницы, body-текст, focus-ring.
3. **Focus-ring — sky, не красный.** `box-shadow: 0 0 0 3px var(--ring)` + `border-color: var(--sky-500)`.
4. **Шрифты — `var(--font-sans)` (Inter) и `var(--font-mono)` (JetBrains Mono).** Mono — для ID, кодов, timestamp'ов, message-id, артикулов. Никогда — для body-текста.
5. **Тексты — sentence case, по-русски, без эмодзи.** Статусы внутри chip — строчными: `на согласовании`, не `На согласовании`.
6. **Числа — табулярные.** `font-feature-settings: 'tnum'`. Деньги: `142 800 ₽` (неразрывный пробел перед ₽).
7. **Плотность — высокая.** Строка таблицы 36px (`--row-h`) или 28px (`--row-h-compact`). Padding ячейки 12px по горизонтали. Карточка — 16–20, не 24+.
8. **Бордеры держат структуру.** Каждая карточка/таблица/инпут — `1px solid var(--border)`. Тени — только меню (`--shadow-md`) и модалки (`--shadow-lg`).
9. **Без иллюстраций, градиентов, паттернов в хроме.** Картинки — только превью вложений / шильдиков.
10. **Motion — 120ms / 180ms, easing `cubic-bezier(0.2,0,0,1)`.** Без spring, без bounce.

## Терминология (использовать дословно)

```
получено · на квалификации · в работе · ждём клиента · refresh цен ·
КП отправлено · счёт выставлен · оплачено · на паузе · закрыто (не наша тема)
```

Бренды как в каталоге, без транслита: `OTIS, KONE, SCHINDLER, ThyssenKrupp (TKE), XIZI Otis, ЩЛЗ, МЛЗ, KMZ, Sigma, WITTUR, FERMATOR, SEMATIC, EHC GLOBAL, Semperit, SKG`.

## Структура каждого раздела

Каждый файл в `sections/` устроен одинаково:

1. **Когда применять** — триггер-фразы пользователя
2. **Anatomy** — ASCII-схема каркаса
3. **Слои сверху вниз** — что и в каком порядке
4. **Plotnost'** (3 варианта: comfortable / default / compact) — где какой брать
5. **Copywriting** — заголовки, лейблы, пустые состояния
6. **Do / Don't** — типичные ошибки
7. **Edge cases** — длинные имена, отсутствие данных, RTL вложений и т.п.
8. **Reference** — ссылки на готовые экраны и preview

Живые примеры лежат рядом: `preview/section-*.html`. Открывай, копируй разметку, адаптируй.
