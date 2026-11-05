---
name: branding-logos
description: "Брендинг mzCorp: лого на странице входа и в шапке приложения (где файлы, размеры, как менять/деплоить)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Визуальный ребрендинг MyLift → **mzCorp** (логотипы; 2026-06-23). Меняли ТОЛЬКО картинки-лого, `config('app.name')` (дефолт 'MyLift CRM' в `<title>` `layouts/app.blade.php`) НЕ трогали.

**Где что (все картинки в `public/images/`, коммитятся в git — НЕ в .gitignore; раздаются nginx как статика):**
- **Страница входа** (`layouts/guest.blade.php`, не `login.blade.php`): `mzcorp-logo.png` (пиксель-арт бородатый mzCorp, 360×360 ~134КБ) — ВНУТРИ карточки формы, `width:100%` (во всю ширину формы), `display:block`, заподлицо с верхом; `px-6 py-4` перенесены на внутренний div со слотом; белый фон лого сливается с белой карточкой (`overflow-hidden`+`rounded-lg` скругляют верх). URL с `?v=3` (cache-bust). Бэкап прежнего (мускулистый) — `public/images/mzcorp-logo-muscle.png` (вернуться: скопировать поверх mzcorp-logo.png). Старый `<x-application-logo>` (Breeze SVG) на логине больше не используется.
- **Шапка приложения** (`layouts/navigation.blade.php`, бренд-`<a>` к dashboard): `mzcorp-emblem.png` (конверт+шестерёнка, 104×96 ~14КБ, height 28px) + `mzcorp-wordmark.png` (стилизованный пиксель-арт «mzCorp», 207×64 ~20КБ, height 22px). Заменили `mylift-wordmark.svg` (остался в public/images, не используется). Размеры — inline-стилями (без новых Tailwind-классов → пересборка не нужна).

**Процесс (как менять лого):** оригинал из Downloads → ресайз через PowerShell `System.Drawing` (HighQualityBicubic), сохранить в `public/images/<name>.png`. Если перезаписываешь тот же файл — **бампни `?v=N`** в blade (иначе браузерный кэш покажет старое). Деплой: `git add` картинки+blade → `git pull` на проде → `view:clear`. **Сборка ассетов НЕ нужна** (это не Vite-ассеты, а статика; размеры inline) — но если добавишь НОВЫЙ Tailwind-класс, см. [[tailwind-arbitrary-classes]].
