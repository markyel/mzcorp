<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800" name="remember">
                <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>

    {{-- 🎮 Мини-аркада «Лови заявки» (смена отдела): ВСЕ менеджеры ловят
         падающие письма, ОДНИМ управляешь ты, остальные — AI. Заявки условно
         распределяются по менеджерам (по близости). Поймал ✉ → менеджер на миг
         замирает, письмо → ₽ (закрыл сделку) или пустышка. Плюс 🚫 спам (затык
         дольше) и 😡 рекламация (ещё дольше) — их лучше не ловить.
         Эмоции аватарки: won при ₽, lost при пустышке/спаме/рекламации. Canvas,
         без зависимостей и новых Tailwind-классов. Свёрнута по умолчанию. --}}
    <details id="mz-arcade" style="margin-top:20px;border-top:1px solid #eee;padding-top:12px">
        <summary style="cursor:pointer;list-style:none;color:#6b7280;font-size:13px;font-weight:600;user-select:none">
            🎮 Размяться перед сменой — «Лови заявки»
        </summary>
        <div style="margin-top:10px">
            <div style="font-size:11px;color:#9ca3af;margin-bottom:4px">Ты играешь за:</div>
            <div id="mz-heroes" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px"></div>
            <canvas id="mz-canvas" style="width:100%;display:block;border-radius:10px;background:linear-gradient(#eef2ff,#f8fafc);touch-action:none;cursor:pointer"></canvas>
            <p style="margin:6px 0 0;font-size:11px;color:#9ca3af;text-align:center">
                Тип виден лишь за границей mzCorp — не липни к ней! Лови ✉ → ₽ и 💎 (+10!). Поймал 🚫 спам / 😡 рекламацию → застрял и откат на старт!
            </p>
        </div>
    </details>

    <script>
    (function () {
        var details = document.getElementById('mz-arcade');
        if (!details) return;
        var booted = false;
        details.addEventListener('toggle', function () {
            if (details.open && !booted) { booted = true; boot(); }
        });

        function boot() {
            var canvas = document.getElementById('mz-canvas');
            var ctx = canvas.getContext('2d');
            var heroesBar = document.getElementById('mz-heroes');
            var dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));

            var managers = [], emails = [], tokens = [];
            var W = 360, H = 420, rM = 19, baseY = 0;
            var BEST_KEY = 'mzArcadeBest';
            var best = parseInt(localStorage.getItem(BEST_KEY) || '0', 10) || 0;
            var state = 'menu', spawnT = 0, timeLeft = 0, ROUND = 45 * 60, playerIdx = 0, superT = 0, superFlash = 0;
            var ptx = 0, pty = 0, PSPEED = 8; // цель игрока (курсор) + макс. скорость (плавно, чтобы откат ощущался)

            function fit() {
                var cssW = canvas.clientWidth || 360, newW = cssW, newH = Math.round(cssW * 1.18);
                if (managers.length && W && H) { managers.forEach(function (m) { m.x = m.x / W * newW; m.y = m.y / H * newH; if (m.homeX != null) m.homeX = m.homeX / W * newW; }); ptx = ptx / W * newW; pty = pty / H * newH; }
                W = newW; H = newH;
                canvas.style.height = H + 'px';
                canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr);
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            }
            fit();
            window.addEventListener('resize', fit);

            function makeImgs(urls) {
                var imgs = {};
                ['neutral', 'won', 'lost'].forEach(function (v) { var im = new Image(); im.src = urls[v] || urls.neutral; imgs[v] = im; });
                return imgs;
            }
            function fallback() {
                managers = [{ imgs: null, name: 'Игрок', x: W / 2, y: 0, ai: false, freeze: 0, mood: 'neutral', moodT: 0, score: 0 }];
                heroesBar.style.display = 'none'; placeManagers();
            }

            // Ростер: один менеджер на сотрудника; пикер выбирает, за кого играешь.
            fetch('{{ route('arcade.roster') }}', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (list) {
                    list = (Array.isArray(list) ? list : []).filter(function (h) { return h.urls && h.urls.neutral; });
                    if (!list.length) { fallback(); return; }
                    managers = list.map(function (h) {
                        return { imgs: makeImgs(h.urls), name: h.name || '', x: 0, y: 0, ai: true, freeze: 0, mood: 'neutral', moodT: 0, score: 0 };
                    });
                    list.forEach(function (h, i) {
                        var b = document.createElement('button');
                        b.type = 'button'; b.title = 'Играть за ' + (h.name || ('игрока ' + (i + 1)));
                        b.style.cssText = 'width:34px;height:34px;border-radius:50%;overflow:hidden;border:2px solid ' + (i === 0 ? '#6366f1' : 'transparent') + ';padding:0;cursor:pointer;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.12)';
                        var pic = document.createElement('img'); pic.src = h.urls.neutral; pic.alt = h.name || '';
                        pic.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block'; b.appendChild(pic);
                        b.addEventListener('click', function () {
                            selectPlayer(i);
                            [].forEach.call(heroesBar.children, function (c) { c.style.borderColor = 'transparent'; });
                            b.style.borderColor = '#6366f1';
                        });
                        heroesBar.appendChild(b);
                    });
                    placeManagers(); selectPlayer(0);
                })
                .catch(fallback);

            function placeManagers() {
                var n = managers.length || 1, by = H - 30;
                managers.forEach(function (m, i) { m.x = W * (i + 1) / (n + 1); m.y = by; m.homeX = m.x; });
            }
            function selectPlayer(i) {
                playerIdx = i;
                managers.forEach(function (m, idx) { m.ai = (idx !== i); });
                var p = managers[i]; if (p) { ptx = p.x; pty = p.y; }
            }
            // откат менеджера на старт (домашняя колонка, низ) — штраф за плохое письмо
            function resetToStart(m) {
                m.x = (m.homeX != null ? m.homeX : W / 2); m.y = H - 30;
                if (m === managers[playerIdx]) { ptx = m.x; pty = m.y; }
            }
            function closestMgr(x) {
                var bm = null, bd = 1e9;
                managers.forEach(function (m) { var d = Math.abs(m.x - x); if (d < bd) { bd = d; bm = m; } });
                return bm;
            }

            // Управление игроком (своего героя нельзя двигать в момент «замирания»).
            function moveTo(clientX, clientY) {
                var rect = canvas.getBoundingClientRect();
                ptx = Math.max(rM, Math.min(W - rM, clientX - rect.left));
                pty = Math.max(Math.round(H * 0.50), Math.min(H - 30, clientY - rect.top));
            }
            canvas.addEventListener('pointermove', function (e) { if (state === 'play') moveTo(e.clientX, e.clientY); });
            canvas.addEventListener('pointerdown', function (e) { if (state !== 'play') start(); else moveTo(e.clientX, e.clientY); });
            window.addEventListener('keydown', function (e) {
                if (!details.open) return;
                var topY = Math.round(H * 0.50), botY = H - 30;
                if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) >= 0 && state === 'play') e.preventDefault();
                if (e.key === 'ArrowLeft') ptx = Math.max(rM, ptx - 26);
                else if (e.key === 'ArrowRight') ptx = Math.min(W - rM, ptx + 26);
                else if (e.key === 'ArrowUp') pty = Math.max(topY, pty - 24);
                else if (e.key === 'ArrowDown') pty = Math.min(botY, pty + 24);
                else if ((e.key === ' ' || e.key === 'Enter') && state !== 'play') { e.preventDefault(); start(); }
            });

            function start() {
                if (!managers.length) return;
                state = 'play'; emails = []; tokens = []; spawnT = 0; timeLeft = ROUND;
                superT = 540 + Math.floor(Math.random() * 420); superFlash = 0; // первая 💎 через ~9–16с
                managers.forEach(function (m) { m.score = 0; m.freeze = 0; m.mood = 'neutral'; m.moodT = 0; });
                placeManagers();
                var pm = managers[playerIdx]; if (pm) { ptx = pm.x; pty = pm.y; }
            }

            function update() {
                baseY = H - 30;
                if (--timeLeft <= 0) { gameOver(); return; }
                // спавн писем (распределяются по всей ширине → по менеджерам):
                // 70% ✉ заявка, 22% 🚫 спам, 8% 😡 рекламация.
                if (--spawnT <= 0) {
                    var rr = Math.random();
                    var typ = rr < 0.70 ? 'mail' : (rr < 0.92 ? 'spam' : 'claim');
                    emails.push({ x: 18 + Math.random() * (W - 36), y: -18, vy: 1.8 + Math.random() * 1.4, type: typ, revealed: false });
                    spawnT = Math.max(13, 28 - Math.floor((ROUND - timeLeft) / 260));
                }
                if (superFlash > 0) superFlash--;
                // редкая 💎 СУПЕРЗАЯВКА — событие, за которым охотятся ВСЕ (+10 ₽).
                // Падает медленно (успеть сбежаться), по одной за раз.
                if (--superT <= 0) {
                    if (!emails.some(function (e) { return e.type === 'super'; })) {
                        emails.push({ x: 30 + Math.random() * (W - 60), y: -20, vy: 1.1 + Math.random() * 0.5, type: 'super', revealed: false });
                    }
                    superT = 720 + Math.floor(Math.random() * 480); // следующая через ~12–20с
                }
                // ГРАНИЦА mzCorp (topY): тип письма опознаётся (виден игроку и AI)
                // ТОЛЬКО после пересечения границы. До этого — «неопознанное»
                // (призрачный ✉). Кто липнет к границе — ловит плохое вслепую.
                var topY = Math.round(H * 0.50);
                var sup = null;
                emails.forEach(function (e) {
                    if (!e.revealed && e.y >= topY) { e.revealed = true; if (e.type === 'super') superFlash = 70; }
                    if (e.type === 'super' && e.revealed) sup = e; // супер преследуют все, но лишь после опознания
                });
                // движение менеджеров (по X и Y, в нижней полосе [topY..baseY])
                managers.forEach(function (m) {
                    if (m.moodT > 0 && --m.moodT === 0) m.mood = 'neutral';
                    if (m.freeze > 0) { m.freeze--; return; }
                    if (!m.ai) { // игрок плавно едет к цели курсора (откат на старт ощущается)
                        m.x = Math.max(rM, Math.min(W - rM, m.x + Math.max(-PSPEED, Math.min(PSPEED, ptx - m.x))));
                        m.y = Math.max(topY, Math.min(baseY, m.y + Math.max(-PSPEED, Math.min(PSPEED, pty - m.y))));
                        return;
                    }
                    // угроза — опознанное плохое письмо рядом → уворачиваемся в сторону и вниз
                    var threat = null, thd = (rM + 36) * (rM + 36);
                    emails.forEach(function (e) {
                        if (!e.revealed || (e.type !== 'spam' && e.type !== 'claim')) return;
                        var dx = e.x - m.x, dy = e.y - m.y, d2 = dx * dx + dy * dy;
                        if (d2 < thd) { thd = d2; threat = e; }
                    });
                    if (threat) {
                        m.x = Math.max(rM, Math.min(W - rM, m.x + (m.x <= threat.x ? -3.2 : 3.2)));
                        m.y = Math.max(topY, Math.min(baseY, m.y + 2.4));
                        return;
                    }
                    // цель: супер (если опознан) или ближайшее НЕ-плохое в зоне
                    var tgt = sup;
                    if (!tgt) {
                        var td = 1e9;
                        emails.forEach(function (e) {
                            if (e.y > baseY + 4 || closestMgr(e.x) !== m) return;
                            if (e.revealed && (e.type === 'spam' || e.type === 'claim')) return; // опознанное плохое не берём
                            var d = Math.abs(e.x - m.x) + Math.abs(e.y - m.y) * 0.35;
                            if (d < td) { td = d; tgt = e; }
                        });
                    }
                    if (tgt) {
                        // неопознанное — ждём ЧУТЬ НИЖЕ границы (запас на реакцию);
                        // опознанное хорошее — идём ловить.
                        var aimY = tgt.revealed ? Math.max(topY, Math.min(baseY, tgt.y)) : Math.round((topY + baseY) / 2);
                        m.x = Math.max(rM, Math.min(W - rM, m.x + Math.max(-3.0, Math.min(3.0, tgt.x - m.x))));
                        m.y = Math.max(topY, Math.min(baseY, m.y + Math.max(-3.0, Math.min(3.0, aimY - m.y))));
                    }
                });
                // письма падают + ловля ПО БЛИЗОСТИ (любой свободный менеджер в радиусе)
                var CR2 = (rM + 13) * (rM + 13);
                for (var i = emails.length - 1; i >= 0; i--) {
                    var e = emails[i]; e.y += e.vy;
                    var m2 = null, bd = CR2;
                    managers.forEach(function (mm) {
                        if (mm.freeze > 0) return;
                        var dx = mm.x - e.x, dy = mm.y - e.y, d2 = dx * dx + dy * dy;
                        if (d2 < bd) { bd = d2; m2 = mm; }
                    });
                    if (m2) {
                        var kind;
                        if (e.type === 'super') { m2.freeze = 16; m2.score += 10; m2.mood = 'won'; kind = 'super'; } // 💎 джекпот +10 ₽
                        else if (e.type === 'spam') { m2.freeze = 54; m2.mood = 'lost'; kind = 'spam'; resetToStart(m2); }   // спам — застрял + откат на старт
                        else if (e.type === 'claim') { m2.freeze = 96; m2.mood = 'lost'; kind = 'claim'; resetToStart(m2); } // рекламация — надолго + откат
                        else { // заявка
                            m2.freeze = 16; // замер на мгновение
                            var ruble = Math.random() < 0.62; // ₽ закрыл / пустышка
                            if (ruble) { m2.score++; m2.mood = 'won'; kind = 'ruble'; } else { m2.mood = 'lost'; kind = 'dummy'; }
                        }
                        m2.moodT = m2.freeze + 2; // эмоция держится весь «затык»
                        tokens.push({ x: e.x, y: e.y - rM, life: 40, kind: kind });
                        emails.splice(i, 1); continue;
                    }
                    if (e.y > baseY + 22) emails.splice(i, 1); // мимо
                }
                for (var k = tokens.length - 1; k >= 0; k--) { tokens[k].y -= 1.1; if (--tokens[k].life <= 0) tokens.splice(k, 1); }
            }

            function gameOver() {
                state = 'over';
                var me = managers[playerIdx];
                if (me && me.score > best) { best = me.score; localStorage.setItem(BEST_KEY, String(best)); }
            }

            function drawManager(m, isPlayer) {
                var y = m.y; // менеджеры теперь и по вертикали
                ctx.save();
                if (m.freeze > 0) ctx.globalAlpha = 0.4; // «затык» — полупрозрачный, ничего не ловит
                ctx.beginPath(); ctx.arc(m.x, y, rM, 0, 7); ctx.closePath(); ctx.fillStyle = '#e0e7ff'; ctx.fill();
                var img = m.imgs ? (m.imgs[m.mood] || m.imgs.neutral) : null;
                if (img && img.complete && img.naturalWidth) {
                    ctx.save(); ctx.beginPath(); ctx.arc(m.x, y, rM - 2, 0, 7); ctx.clip();
                    ctx.drawImage(img, m.x - (rM - 2), y - (rM - 2), (rM - 2) * 2, (rM - 2) * 2); ctx.restore();
                } else {
                    ctx.font = '22px system-ui'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillText(m.mood === 'won' ? '😄' : (m.mood === 'lost' ? '😞' : '🧑‍💼'), m.x, y);
                }
                ctx.lineWidth = isPlayer ? 3 : 2;
                ctx.strokeStyle = m.mood === 'won' ? '#10b981' : (m.mood === 'lost' ? '#ef4444' : (isPlayer ? '#6366f1' : '#c7d2fe'));
                ctx.beginPath(); ctx.arc(m.x, y, rM, 0, 7); ctx.stroke();
                ctx.restore();
                ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
                if (m.freeze > 0) { ctx.font = '13px system-ui'; ctx.fillStyle = '#94a3b8'; ctx.fillText('…', m.x, y - rM - 5); }
                if (m.score > 0) { ctx.font = 'bold 11px system-ui'; ctx.fillStyle = isPlayer ? '#4f46e5' : '#9ca3af'; ctx.fillText(m.score + '₽', m.x, y - rM - (m.freeze > 0 ? 17 : 5)); }
                if (isPlayer) { ctx.font = 'bold 9px system-ui'; ctx.fillStyle = '#4f46e5'; ctx.fillText('ВЫ', m.x, y + rM + 11); }
            }

            function draw() {
                ctx.clearRect(0, 0, W, H);
                baseY = H - 30;
                ctx.strokeStyle = 'rgba(99,102,241,.07)'; ctx.lineWidth = 1;
                for (var gx = 40; gx < W; gx += 40) { ctx.beginPath(); ctx.moveTo(gx, 0); ctx.lineTo(gx, H); ctx.stroke(); }
                ctx.strokeStyle = 'rgba(99,102,241,.18)'; ctx.beginPath(); ctx.moveTo(0, baseY + rM + 3); ctx.lineTo(W, baseY + rM + 3); ctx.stroke();
                // граница mzCorp — за ней письмо опознаётся (выше менеджеры не заходят)
                var bY = Math.round(H * 0.50);
                ctx.save(); ctx.setLineDash([5, 4]); ctx.strokeStyle = 'rgba(99,102,241,.4)';
                ctx.beginPath(); ctx.moveTo(0, bY); ctx.lineTo(W, bY); ctx.stroke(); ctx.setLineDash([]);
                ctx.fillStyle = 'rgba(99,102,241,.6)'; ctx.font = '9px system-ui'; ctx.textAlign = 'right'; ctx.textBaseline = 'bottom';
                ctx.fillText('граница mzCorp ▾', W - 6, bY - 1); ctx.restore();

                // падающие: ✉ заявка / 🚫 спам / 😡 рекламация / 💎 суперзаявка
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                var EMO = { mail: '✉️', spam: '🚫', claim: '😡' };
                emails.forEach(function (e) {
                    if (!e.revealed) { // не опознано — призрачный конверт
                        ctx.save(); ctx.globalAlpha = 0.5;
                        ctx.font = '20px system-ui, "Segoe UI Emoji", "Apple Color Emoji"';
                        ctx.fillText('✉️', e.x, e.y); ctx.restore();
                    } else if (e.type === 'super') {
                        ctx.save();
                        ctx.shadowColor = 'rgba(234,179,8,.95)'; ctx.shadowBlur = 16;
                        ctx.font = (28 + 3 * Math.sin(e.y * 0.18)).toFixed(0) + 'px system-ui, "Segoe UI Emoji", "Apple Color Emoji"';
                        ctx.fillText('💎', e.x, e.y);
                        ctx.restore();
                    } else {
                        ctx.font = '22px system-ui, "Segoe UI Emoji", "Apple Color Emoji"';
                        ctx.fillText(EMO[e.type] || '✉️', e.x, e.y);
                    }
                });

                // токены результата
                tokens.forEach(function (tk) {
                    var a = Math.min(1, tk.life / 24);
                    if (tk.kind === 'super') { ctx.fillStyle = 'rgba(202,138,4,' + a + ')'; ctx.font = 'bold 17px system-ui'; ctx.fillText('+10 ₽', tk.x, tk.y); }
                    else if (tk.kind === 'ruble') { ctx.fillStyle = 'rgba(202,138,4,' + a + ')'; ctx.font = 'bold 19px system-ui'; ctx.fillText('₽', tk.x, tk.y); }
                    else if (tk.kind === 'spam') { ctx.fillStyle = 'rgba(234,88,12,' + a + ')'; ctx.font = 'bold 10px system-ui'; ctx.fillText('спам', tk.x, tk.y); }
                    else if (tk.kind === 'claim') { ctx.fillStyle = 'rgba(220,38,38,' + a + ')'; ctx.font = 'bold 10px system-ui'; ctx.fillText('рекламация', tk.x, tk.y); }
                    else { ctx.fillStyle = 'rgba(148,163,184,' + a + ')'; ctx.font = '11px system-ui'; ctx.fillText('пусто', tk.x, tk.y); }
                });

                managers.forEach(function (m, i) { drawManager(m, i === playerIdx); });

                // HUD
                var me = managers[playerIdx];
                ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic'; ctx.fillStyle = '#374151'; ctx.font = 'bold 14px system-ui';
                ctx.fillText('ВЫ: ' + (me ? me.score : 0) + ' ₽', 10, 18);
                ctx.textAlign = 'right'; ctx.fillStyle = (state === 'play' && timeLeft < 600) ? '#ef4444' : '#6b7280'; ctx.font = 'bold 13px system-ui';
                ctx.fillText('⏱ ' + Math.ceil(Math.max(0, timeLeft) / 60) + 'с', W - 10, 18);

                // баннер появления суперзаявки
                if (state === 'play' && superFlash > 0) {
                    ctx.textAlign = 'center'; ctx.fillStyle = 'rgba(202,138,4,' + Math.min(1, superFlash / 22) + ')';
                    ctx.font = 'bold 14px system-ui';
                    ctx.fillText('💎 СУПЕРЗАЯВКА! +10 ₽', W / 2, 40);
                }

                if (state !== 'play') {
                    ctx.fillStyle = 'rgba(15,23,42,.62)'; ctx.fillRect(0, 0, W, H);
                    ctx.textAlign = 'center'; ctx.fillStyle = '#fff'; ctx.font = 'bold 20px system-ui';
                    ctx.fillText(state === 'over' ? 'Смена окончена' : 'Лови заявки!', W / 2, 38);
                    if (state === 'over') {
                        var sorted = managers.slice().sort(function (a, b) { return b.score - a.score; });
                        sorted.slice(0, 8).forEach(function (m, idx) {
                            var isMe = (m === managers[playerIdx]);
                            ctx.fillStyle = isMe ? '#a5b4fc' : '#e5e7eb'; ctx.font = (isMe ? 'bold ' : '') + '13px system-ui';
                            ctx.fillText((idx + 1) + '. ' + (m.name || 'Игрок') + ' — ' + m.score + ' ₽' + (isMe ? '  ← ВЫ' : ''), W / 2, 68 + idx * 19);
                        });
                        ctx.fillStyle = '#c7d2fe'; ctx.font = '12px system-ui';
                        ctx.fillText('ваш рекорд: ' + best + ' ₽', W / 2, 68 + Math.min(8, sorted.length) * 19 + 14);
                    } else {
                        ctx.font = '14px system-ui'; ctx.fillText('Выбери, за кого играть, и жми старт', W / 2, H / 2);
                    }
                    ctx.font = 'bold 14px system-ui'; ctx.fillStyle = '#a5b4fc';
                    ctx.fillText('▶ нажми, чтобы ' + (state === 'over' ? 'ещё смену' : 'начать'), W / 2, H - 20);
                }
            }

            function loop() { if (state === 'play') update(); draw(); requestAnimationFrame(loop); }
            loop();
        }
    })();
    </script>
</x-guest-layout>
