# MyLift CRM — Design System Skill

You are designing screens, components, or motion for **MyLift CRM**, the internal request-management tool for **Мой ЗиП / MyLift** (Russia's first specialised B2B distributor of elevator and escalator spare parts; warehouse in Moscow, ~17 000 SKUs, 4 000+ active customers, mailbox-driven workflow on Yandex 360 + 1С back-office).

It is **not** the public catalogue (mylift.ru). It is the daily-driver tool for **5–6 sales managers, the РОП (sales lead), the secretary, and the directorate** — high-density, Russian-language, used 6+ hours a day.

## Read these in order

1. **`README.md`** — full briefing: positioning, voice, content fundamentals, visual foundations, iconography. Read every section before designing.
2. **`colors_and_type.css`** — every token. Always import this file from `<link rel="stylesheet" href="…/colors_and_type.css">` or `@import` it. Never redeclare colours, font families, sizes, spacing, radii, shadows, or motion durations — they exist as CSS variables.
3. **`uploads/MyLift_Foundation.md`** (if present) — full product spec. Lift exact terminology (`sticky-проверка`, `attention`, `pause`, `refresh цен`, `КП → счёт`).
4. **`preview/`** — design-system reference cards. Look here before inventing a new colour, chip, or button style.
5. **`ui_kits/crm/`** — full screens (Pool, Dashboard) showing the system in action. Compose new screens by **copying these layouts**, not from scratch.

## Hard rules (non-negotiable)

- **Russian, B2B, sentence-case.** No emoji. No exclamation marks. No marketing voice. Status labels in chips are **lowercase** (`на согласовании`, `просрочено 2ч 14м`).
- **One accent.** `var(--accent)` (= `#D32027`, MyZip red) is the *only* saturated colour and only on primary CTAs, the active nav-rail bar, critical badges. Never as body text or page background.
- **Tabular numerals.** Every number in tables, KPIs, monetary amounts, IDs gets `font-feature-settings: 'tnum'`. Money uses `₽` after a non-breaking space: `142 800 ₽`.
- **Dense rows.** Default table row 36px (`--row-h`), compact 28px (`--row-h-compact`), cell padding 12px horizontal. Card padding 16–20px. Never 24+ in data UI.
- **Borders carry the structure.** Cards/inputs/tables have a 1px hairline (`--border`). Shadows are reserved for menus (`--shadow-md`) and modals (`--shadow-lg`) only — never decorative.
- **Body 13px / dense 12px.** Never below 11px (illegible Cyrillic). Headings 16 / 20 / 24 / 28 / 32 — see scale in CSS.
- **Inter + JetBrains Mono.** Mono is mandatory for IDs (`#MZ-21384`), email message-IDs, code, log timestamps. Sans-only otherwise.
- **Status chip set is closed.** `info / attn / over / ok / paused / neutral` — see `preview/chips.html`. Don't invent new colours; remap to one of these six.
- **Motion is 120ms / 180ms, easing `cubic-bezier(0.2,0,0,1)`.** No spring, no bounce.
- **Focus ring is sky, not red.** `var(--ring)` (sky-500 @ 40 %). Brand red is for selection-affordance and CTAs, never focus.
- **No imagery, no gradients, no patterns** in app chrome. Imagery only inside attachment thumbnails / nameplate photos.

## Lifecycle vocabulary (use exactly)

`получено · на квалификации · в работе · ждём клиента · refresh цен · КП отправлено · счёт выставлен · оплачено · на паузе · закрыто (не наша тема)`

Brands referenced verbatim from the catalogue: `OTIS, KONE, SCHINDLER, ThyssenKrupp (TKE), XIZI Otis, ЩЛЗ, МЛЗ, KMZ, Sigma, WITTUR, FERMATOR, SEMATIC, EHC GLOBAL, Semperit, SKG`. Don't transliterate or invent.

## When you build a screen

1. Start by **`@import`-ing or `<link>`-ing `colors_and_type.css`**.
2. Use the **three-column shell** (rail · context list · main · optional inspector) from `01-pool.html` for any list-detail flow. Use the **two-column shell** (rail · main) from `02-dashboard.html` for analytics.
3. Use **`var(--*)` tokens for everything**. If you find yourself writing a hex code, a px value not on the 4 grid, or a font size, stop — pick the right token.
4. **Lift copy from the foundation doc.** Don't paraphrase status names or actions.
5. When you need a component that doesn't exist in `preview/`, build it from tokens and **add a preview card** alongside.

## When the user asks for a new variation

If they want exploration on the same screen, wrap it in a `<DCArtboard>` inside a `design_canvas` document — not a new file. Variations stay side-by-side for comparison.

## Substitution flags (declare these to the user)

- **Fonts** — Inter / JetBrains Mono are stand-ins; the brand has not provided a licensed corporate face.
- **Icons** — Lucide is a stand-in; confirm before standardising.
- **Logos** — `assets/logos/*.svg` are reconstructed from the public PNG marks; final lockup needs sign-off from the brand owner.

If the user picks a different font / icon / logo, swap the CSS variable or the asset path and the rest of the system absorbs it without changes.
