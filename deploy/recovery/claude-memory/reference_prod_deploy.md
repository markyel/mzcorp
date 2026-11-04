---
name: prod-deploy
description: "How to deploy mzCorp to production (SSH host, git-pull flow, cache/worker/fpm steps)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-11-04T15:19:22.635Z
---

**С 02.11.2026 это история до сбоя: сервер и базы Beget потеряны, см. [[beget-db-loss-recovery]].**

Production for mzCorp (mzcorp.ru) is a git checkout deployed by pull, not CI.

- **SSH:** `ssh root@<IP>` (resolves from mzcorp.ru; key is the default `~/.ssh/id_ed25519`, already in known_hosts). Direct access works from this environment — the Beget **DB** (`nakoocirope.beget.app` / internal `<IP>`) is NOT reachable locally, so run any DB/tinker queries ON prod via ssh.
- **App path:** `/var/www/mzcorp`, runs as `www-data`, branch `main`, origin `github.com/markyel/mzcorp.git`.
- **Deploy flow:** commit + `git push origin main` locally → on prod `cd /var/www/mzcorp && git pull origin main`.
- **Post-deploy:** `php artisan view:clear` (if blade changed), `php artisan config:clear && php artisan config:cache` (config IS cached on prod), `php artisan queue:restart` (graceful — workers respawn with new code), `systemctl reload php8.3-fpm` (opcache for web/php-fpm requests).
- **ROUTE CACHE — easy to forget (causes hard 500s):** prod caches routes (`bootstrap/cache/routes-v7.php`). Whenever you ADD/CHANGE/REMOVE a route (routes/web.php) OR add a `route('new.name')` reference in a blade, you MUST run `sudo -u www-data php artisan route:clear && sudo -u www-data php artisan route:cache` on prod — otherwise the stale cache lacks the new route and any page calling `route('new.name')` throws `Route [new.name] not defined` → 500 (e.g. the whole dashboard died because a widget linked to a not-yet-cached `analytics.index`). Symptom in `storage/logs/laravel.log`: `production.ERROR: Route [x] not defined`. Same applies to `event:cache` if events change, but routes are the common one.
- **ASSET BUILD (Tailwind/CSS/JS) — easy to forget:** `/public/build` is **gitignored** → CSS/JS is built ON PROD, not committed. Whenever blade/components introduce **new Tailwind utility classes** (e.g. `bg-amber-400`, `bg-black/45`, arbitrary `text-[...]`) or you touch `resources/css`/`resources/js`, you MUST run `sudo -u www-data npm run build` on prod (cd /var/www/mzcorp) — otherwise the new classes are absent from the compiled CSS and styles silently don't apply (symptom: element renders but unstyled; classes already used before, like `bg-[var(--accent)]`, still work because they were in the prior build). Prod has node v20 + npm 10 + node_modules (197 pkgs); `vite build` takes ~2s, emits hashed `public/build/assets/app-*.css|js` + `manifest.json` (Laravel `@vite` reads the manifest at runtime, no view:clear needed). Tier-1 cause of "the new UI works but looks wrong/invisible on prod".
- **Queue workers:** supervisor program `mzcorp-worker` (4 procs `_00`.._03`). `supervisorctl status` to check; new PIDs after `queue:restart` confirm fresh code loaded. Extra worker logs: `storage/logs/worker-extra-*.log`.
- **Runtime assets that must ship via git** (KP PDF depends on them, prod had none until 2026-05-29): `resources/fonts/` (PT Sans/Mono TTF), `public/assets/logos/myzip-corporate.svg`, `public/assets/stamp/{signature,stamp}.png`.
- **Tooling on prod:** `pdfinfo` (poppler) and `python3` available — handy to verify generated PDFs (`pdfinfo file.pdf` → Pages/Page size).
- **Грабли — dompdf font cache:** КП-PDF рендерит dompdf, кэш шрифтов в `storage/app/dompdf/fonts/` (включая `installed-fonts.json`). Регистрация нового начертания (`FontMetrics::registerFont`) ПЕРЕЗАПИСЫВАЕТ `installed-fonts.json`. Если прогонять рендер на проде под root (`php artisan tinker`/`php script.php` от root по ssh), кэш-файлы становятся root-owned → следующий веб-запрос под www-data падает с 500 `file_put_contents(...installed-fonts.json): Permission denied`. **Всегда** запускай рендер-проверки на проде через `sudo -u www-data php artisan tinker ...`, а если уже наследил — `chown -R www-data:www-data storage/app/dompdf/fonts/`.

**Why:** mzCorp has no automated deploy; everything is manual over SSH. **How to apply:** use this exact sequence when asked to deploy; always restart workers AND reload fpm so both queued jobs and web requests pick up new code.

## www vs non-www → 419 «page expired» (fix 2026-08-20, nginx)
Симптом: «This page has expired / Would you like to refresh?» (Livewire 419 CSRF) + «письма не уходят» — но ТОЛЬКО у пользователя на `www.mzcorp.ru`; у остальных (на `mzcorp.ru`) всё ок. Причина: `SESSION_DOMAIN=null` → кука сессии привязана к точному хосту, а `www` и non-www обслуживались ОДНИМ server-блоком nginx без редиректа → разные сессии/CSRF; ссылки приложения (APP_URL=https://mzcorp.ru) ведут на non-www → рассинхрон. НЕ путать с read-only/квотой БД (там письма не уходят у ВСЕХ; проверять write-тест + last outbound sent_at). Фикс: в `/etc/nginx/sites-available/mzcorp.conf` в 443-блоке после `server_name` добавлен `if ($host = www.mzcorp.ru) { return 301 https://mzcorp.ru$request_uri; }` → `nginx -t` → `systemctl reload nginx`. Бэкап рядом (mzcorp.conf.bak-*). Диагностика: `curl -sI https://www.mzcorp.ru/...` (ждём 301 на mzcorp.ru), `config('session.domain')`, `config('app.url')`.
