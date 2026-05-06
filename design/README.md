# MyLift CRM — Design System

> Internal CRM for **MyLift** (group: Мой ЗиП, mylift.ru / myzip.ru) — Russia's first specialised B2B distributor of elevator and escalator spare parts (since 2009). This system is **not** the public catalogue site — it is the internal tool the sales team uses to receive, distribute and track customer requests for parts.

## What MyLift CRM is

A **CRM layer** sitting between the company's incoming Yandex 360 mailboxes and its corporate back-office (1C). Manager-facing tool used daily by:

- **Менеджер** (5–6 sales managers) — work an assigned request pool
- **РОП** (1 sales lead) — distribution oversight, dashboards, system settings
- **Секретарь** — distribution-routing audit
- **Директорат** — analytics + Knowledge Base curation

The CRM does **not** replace the corporate base. It collects email requests, distributes them fairly between managers (with sticky rules per `catalog_item_id`), helps assemble a quote against a read-only catalogue mirror, requests price refreshes from suppliers, monitors the SLA, and exports a closed-request summary back to 1C.

KPIs: time from request → sent КП (commercial proposal), share of unanswered requests → 0, КП → счёт (invoice) → paid conversion.

## Source materials

- `uploads/MyLift_Foundation.md` — full product/architecture spec (1162 lines). Read first for any feature work.
- Public sites for brand visual context: https://mylift.ru, https://myzip.ru, https://myzip.ru/about/
- No Figma file or codebase was provided. **Visual language is reconstructed** from the public sites + the foundation doc, adapted to the density of an enterprise data tool.

## Index

| File | What it is |
|---|---|
| `README.md` | This file — context, content fundamentals, visual foundations, iconography |
| `colors_and_type.css` | All design tokens (colours, typography, spacing, radii, shadows, motion) |
| `SKILL.md` | Agent Skill manifest |
| `assets/` | Logos, brand imagery references |
| `preview/` | Design-system cards (palette, type scale, components) |
| `ui_kits/crm/` | High-fidelity recreation of the CRM screens (Pool, Request, Dashboard, Mail rules, Supplier pool) |

---

## Content fundamentals

### Voice

Confident, technical, plainspoken Russian B2B. The user is addressed informally inside the app (this is an internal tool — no `Вы` capitalisation lift, just normal Russian). Outward-facing emails to clients and suppliers use polite-form `Вы / Ваш` (inherits the public-site convention).

- **Lexicon is mixed Russian + transliterated English technical terms.** Managers say *КП* (commercial proposal), *счёт* (invoice), *рефреш цен*, *batch*, *sticky*, *sla*. The product spec literally uses *Sticky-проверки*, *Pause*, *Attention*. We mirror that.
- **No marketing fluff.** No "🚀", no exclamation marks, no "boost your productivity". The label is `Заявки в работе`, not `Управление вашими заявками`.
- **Numbers and units are loud.** SKU counts, m² of warehouse, days overdue, % of SLA breach, ₽ of pending quotes. This is a B2B data tool — quantities are the headline.
- **Statuses are the noun of the system.** `получено`, `на квалификации`, `в работе`, `на согласовании`, `КП отправлено`, `счёт выставлен`, `оплачено`, `закрыто (не наша тема)`. Use the exact taxonomy from the foundation doc — managers will be drilled on it.
- **Casing.** Sentence case for everything. Russian section headers are `Заявки в работе`, not `ЗАЯВКИ В РАБОТЕ`. Statuses inside chips are lowercase: `на согласовании`. ALL-CAPS only for inline acronyms (SLA, КП, MVP).
- **No emoji.** Status uses coloured dots / chips / bordered icons.
- **Tables and chips do the work, prose is rare.** Empty-state text is one short imperative sentence: `Нет заявок, требующих внимания.`

### Examples (lift the exact phrasing for new copy)

| Surface | Copy |
|---|---|
| Pool empty state | `Все заявки разобраны. Хорошая работа.` |
| SLA-breach badge | `Просрочено · 2 ч 14 мин` |
| Pause action | `Пауза до 15.01` |
| Refresh CTA | `Запросить актуализацию цен` |
| Reassign confirm | `Переподчинить 7 открытых заявок?` |
| Off-topic close | `Заявка закрыта как не наша тема. РОП может вернуть.` |
| Toast (assignment) | `Назначено: Иванов А.` |

---

## Visual foundations

The product is an **enterprise data tool, in Russian, used 6+ hours a day by power-users**. It must feel close to Yandex 360 / 1C-Предприятие / Kaiten / Bitrix24 — not to a SaaS landing page.

### Mental model
- **Density first.** Tables, chips, sidebars. Whitespace is calm but tight.
- **One accent, many neutrals.** A single MyZip-red accent is the only saturated colour. Everything else is a 9-step neutral grey scale plus four functional status hues.
- **Type does the heavy lifting**, not illustration. There are no decorative gradients, no big imagery, no hand-drawn icons.

### Colours
- **Brand red** `#D32027` — derived from the MyZip / mylift.ru logo lockup. Used **only** for primary CTAs, the active nav rail indicator, and unread/critical badges. Never for body text or backgrounds.
- **Neutrals** are warm-cool slate (oklch-tuned, not pure grey) — surface, line, muted text, etc. 9 steps.
- **Status hues** — amber (attention), red (overdue/error), emerald (paid/won), sky (info/awaiting reply), violet (paused). Used as 50/600/700 chip-and-text pairs, never as full surfaces.
- See `colors_and_type.css` for the complete token list. All semantic vars (`--fg-1`, `--bg-surface`, `--border`, `--accent`, etc.) reference base scale steps.

### Typography
- **Display + UI:** `Inter` (variable, weights 400 / 500 / 600 / 700). Excellent Cyrillic, neutral, dense at small sizes. Substituted from Google Fonts — flagged below.
- **Mono:** `JetBrains Mono` for IDs (`#MZ-21384`), email message-IDs, code blocks, log lines. Excellent Cyrillic.
- **Numerals are tabular.** `font-feature-settings: 'tnum'` is on by default for tables and metrics so columns of numbers line up.
- **Body size** is 13px. **Dense rows** are 12px. Large headings (dashboard metrics) are 28–40px. Nothing is ever 11px or smaller — Cyrillic at 11px is illegible.
- **Line-height** is tight (1.35) for dense UI, 1.5 for prose.
- ⚠ **Font substitution flag.** No font files were provided by the brand. We use Google-Fonts Inter + JetBrains Mono as the closest match to the public-site sans-serif lockup. **Action requested:** confirm or replace with the licensed corporate font.

### Spacing and rhythm
- 4px base grid. Tokens: `--s-1` 4 / `--s-2` 8 / `--s-3` 12 / `--s-4` 16 / `--s-5` 24 / `--s-6` 32 / `--s-7` 48 / `--s-8` 64.
- **Row height** in tables is 36px (default) or 28px (compact mode). Cells have 12px horizontal padding.
- **Card padding** is 16 or 20. Never 24 inside data UI.

### Backgrounds
- **No imagery, no gradients, no patterns.** App background is `--bg-app` (very pale slate). Surfaces are pure white. Sidebar is a slightly cooler neutral. Login/empty screens get one large outlined neutral icon, that's it.
- The only "atmospheric" element is a very subtle 1px hairline on every divider — `--border` at oklch lightness ~92%.

### Borders, radii, shadows
- **Radii.** `--r-sm` 4, `--r-md` 6, `--r-lg` 8, `--r-pill` 999. The whole app uses 6 by default. 8 only for big elevated cards (modal). Pill for status chips and avatars.
- **Borders** carry the structure. Every card, input, table cell-group has a `1px solid --border`. **No** floating shadowed cards by default.
- **Shadows are minimal.** `--shadow-sm` for hover states, `--shadow-md` for menus and popovers, `--shadow-lg` for modals only. No coloured shadows. No glow.

### States

| State | How |
|---|---|
| Hover (interactive surface) | background → `--bg-hover` (one step up from default surface) |
| Hover (button, primary) | accent → `--accent-600` (slightly darker) |
| Press / active | translateY(0) + accent-700, OR ring `--ring` (2px) on form controls |
| Focus-visible | 2px ring `--ring` (sky-500 at 40% alpha) — never the brand red |
| Selected row | `--bg-selected` (sky-50) + 2px sky-500 left border bar |
| Disabled | opacity 0.5, no pointer events |
| Loading | shimmer of `--bg-skel` 1.4s ease-in-out |

### Motion
- **Fast, mechanical, non-bouncy.** All transitions 120ms (micro) or 180ms (panel). Easing `cubic-bezier(0.2, 0, 0, 1)` (Material standard). **No** spring, **no** bounce.
- Page transitions: instant. Side-panel: 180ms slide-in from right. Toast: 120ms fade + 4px slide.
- Loading spinner is a hairline ring rotating, not pulsing dots.

### Layout
- **Three-column shell**: 56px nav rail · 240px context list (when present) · main canvas. The main canvas itself often has a 360px right inspector panel.
- **Sticky headers** on long tables (table header + first column for the dashboard).
- **Top bar** is 48px tall, fixed. Contains brand wordmark, global search (`/`), workspace switcher (mailbox), notifications bell, user menu.
- **Max content width** is 1440px in dashboards; tables stretch full-width.

### Use of transparency / blur
- Only on the **command palette** (Cmd+K) and the **right inspector overlay** on small screens — `backdrop-filter: blur(8px)` on a `rgba(15,18,23,0.4)` scrim. Nowhere else.

### Imagery vibe
- The CRM has almost no photography. The one place it appears is **client attachment thumbnails** (lift nameplate photos, PDF first-page renders) — these are shown in their natural colour, never tinted, with a 1px border and 6px radius.

---

## Iconography

**System: [Lucide](https://lucide.dev) via CDN, stroke 1.5.** Loaded from `https://unpkg.com/lucide@latest`. Justification: the public sites use generic PNG sprites with no consistent system, and the foundation doc gives us no icon set, so we substitute a CDN set that pairs well with Inter and Cyrillic UIs.

⚠ **Substitution flag.** Confirm with the team whether they want to standardise on Lucide, switch to **Tabler Icons** (also free, slightly more enterprise-tuned), or buy into a paid set (Phosphor, Iconoir).

### Usage rules
- Only the **outline** style. Stroke 1.5px. No filled icons.
- Sizes: **16px** in inline / chip contexts, **20px** in toolbars / buttons, **24px** in section headers, **40px** for empty-state hero icons (with reduced opacity).
- Icon colour follows text colour by default (`currentColor`). Status icons inherit the chip's accent.
- **Never** an icon without a label, except in: nav rail (with tooltip), table row actions menu trigger (`MoreHorizontal`), and the global toolbar buttons that have a tooltip on hover.

### Logos
- `assets/logos/mylift-wordmark.svg` — primary CRM wordmark (clean, type-only, used in top bar).
- `assets/logos/myzip-corporate.svg` — parent group lockup (used on login screen / footer).
- The public-site PNG logos (`https://mylift.ru/images/logo.png`, `https://myzip.ru/images/logo.png`) are referenced inline as a fallback for higher fidelity. Local SVG re-cuts are provided to keep the kit self-contained.

### Emoji & unicode
- **No emoji** anywhere in the UI. The closest we get is the tabular-numeral arrow `↗` / `↘` for trend indicators and `·` (middot) as a meta-separator.

