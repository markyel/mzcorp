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

    {{-- 🎮 Мини-аркада «Лови заявки» — размяться перед сменой. Герой =
         аватарка сотрудника. Полностью самодостаточно (vanilla JS + canvas),
         без новых Tailwind-классов и зависимостей. Свёрнута по умолчанию. --}}
    <details id="mz-arcade" style="margin-top:20px;border-top:1px solid #eee;padding-top:12px">
        <summary style="cursor:pointer;list-style:none;color:#6b7280;font-size:13px;font-weight:600;user-select:none">
            🎮 Размяться перед сменой — «Лови заявки»
        </summary>
        <div style="margin-top:10px">
            <div id="mz-heroes" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px"></div>
            <canvas id="mz-canvas" style="width:100%;display:block;border-radius:10px;background:linear-gradient(#eef2ff,#f8fafc);touch-action:none;cursor:pointer"></canvas>
            <p style="margin:6px 0 0;font-size:11px;color:#9ca3af;text-align:center">
                Двигай героя мышкой/стрелками. Лови ✉ заявки (+10) и ⚙ бонус (+30), уворачивайся от 💣.
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

            var W = 360, H = 470, dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
            function fit() {
                var cssW = canvas.clientWidth || 360;
                W = cssW; H = Math.round(cssW * 1.3);
                canvas.style.height = H + 'px';
                canvas.width = Math.round(W * dpr);
                canvas.height = Math.round(H * dpr);
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            }

            // heroSet = {neutral, won, lost} (Image-объекты, won/lost фолбэк на
            // neutral). mood переключает эмоцию: 'won' при «поймал», 'lost' при 💣.
            var heroSet = null, heroName = '', mood = 'neutral', moodT = 0;
            var BEST_KEY = 'mzArcadeBest';
            var best = parseInt(localStorage.getItem(BEST_KEY) || '0', 10) || 0;
            var state = 'menu', score = 0, lives = 3, items = [], heroX = 0, spawnT = 0, t = 0;
            var R = 26; // радиус героя

            // Ростер героев (аватарки сотрудников, по 3 эмоции).
            fetch('{{ route('arcade.roster') }}', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (list) {
                    if (!Array.isArray(list) || !list.length) { heroesBar.style.display = 'none'; return; }
                    list.forEach(function (h, i) {
                        var urls = h.urls || {};
                        if (!urls.neutral) return;
                        // Преднагрузка 3 эмоций (won/lost → neutral если нет).
                        var imgs = {};
                        ['neutral', 'won', 'lost'].forEach(function (v) {
                            var im = new Image(); im.src = urls[v] || urls.neutral; imgs[v] = im;
                        });
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.title = h.name || ('Игрок ' + (i + 1));
                        b.style.cssText = 'width:34px;height:34px;border-radius:50%;overflow:hidden;border:2px solid transparent;padding:0;cursor:pointer;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.12)';
                        var pic = document.createElement('img');
                        pic.src = urls.neutral; pic.alt = h.name || '';
                        pic.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block';
                        b.appendChild(pic);
                        b.addEventListener('click', function () {
                            heroSet = imgs; heroName = h.name || ''; mood = 'neutral'; moodT = 0;
                            [].forEach.call(heroesBar.children, function (c) { c.style.borderColor = 'transparent'; });
                            b.style.borderColor = '#6366f1';
                        });
                        heroesBar.appendChild(b);
                        if (i === 0) b.click(); // первый герой по умолчанию
                    });
                })
                .catch(function () { heroesBar.style.display = 'none'; });

            fit();
            window.addEventListener('resize', fit);

            // Управление.
            function moveTo(clientX) {
                var rect = canvas.getBoundingClientRect();
                heroX = Math.max(R, Math.min(W - R, clientX - rect.left));
            }
            canvas.addEventListener('pointermove', function (e) { moveTo(e.clientX); });
            canvas.addEventListener('pointerdown', function (e) {
                moveTo(e.clientX);
                if (state !== 'play') start();
            });
            window.addEventListener('keydown', function (e) {
                if (!details.open) return;
                if (e.key === 'ArrowLeft') heroX = Math.max(R, heroX - 28);
                else if (e.key === 'ArrowRight') heroX = Math.min(W - R, heroX + 28);
                else if (e.key === ' ' || e.key === 'Enter') { if (state !== 'play') { e.preventDefault(); start(); } }
            });

            function start() {
                state = 'play'; score = 0; lives = 3; items = []; spawnT = 0; t = 0;
                heroX = W / 2; mood = 'neutral'; moodT = 0;
            }

            function spawn() {
                var roll = Math.random();
                var type = roll < 0.16 ? 'bomb' : (roll < 0.24 ? 'gear' : 'mail');
                var speed = 1.7 + Math.min(3.4, score / 220) + Math.random() * 1.2;
                items.push({ x: 24 + Math.random() * (W - 48), y: -24, vy: speed, type: type, rot: Math.random() * 6 });
            }

            var EMOJI = { mail: '✉️', gear: '⚙️', bomb: '💣' };

            function update() {
                t++;
                if (moodT > 0 && --moodT === 0) mood = 'neutral'; // вернуть нейтральную эмоцию
                spawnT--;
                if (spawnT <= 0) { spawn(); spawnT = Math.max(16, 42 - Math.floor(score / 60)); }
                var hy = H - 42;
                for (var i = items.length - 1; i >= 0; i--) {
                    var it = items[i];
                    it.y += it.vy;
                    var dx = it.x - heroX, dy = it.y - hy;
                    if (dx * dx + dy * dy < (R + 16) * (R + 16)) {
                        if (it.type === 'bomb') { lives--; mood = 'lost'; moodT = 30; if (lives <= 0) gameOver(); }
                        else { score += (it.type === 'gear' ? 30 : 10); mood = 'won'; moodT = 24; pop.push({ x: it.x, y: it.y, life: 18, val: (it.type === 'gear' ? '+30' : '+10') }); }
                        items.splice(i, 1); continue;
                    }
                    if (it.y > H + 24) items.splice(i, 1);
                }
            }

            function gameOver() {
                state = 'over'; mood = 'lost'; // герой расстроен
                if (score > best) { best = score; localStorage.setItem(BEST_KEY, String(best)); }
            }

            var pop = [];

            function draw() {
                ctx.clearRect(0, 0, W, H);
                // лёгкая «шахта»
                ctx.strokeStyle = 'rgba(99,102,241,.08)';
                ctx.lineWidth = 1;
                for (var gx = 40; gx < W; gx += 40) { ctx.beginPath(); ctx.moveTo(gx, 0); ctx.lineTo(gx, H); ctx.stroke(); }

                // падающие предметы
                ctx.font = '26px system-ui, "Segoe UI Emoji", "Apple Color Emoji"';
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                items.forEach(function (it) { ctx.fillText(EMOJI[it.type], it.x, it.y); });

                // всплывашки очков
                for (var i = pop.length - 1; i >= 0; i--) {
                    var p = pop[i]; p.y -= 1.2; p.life--;
                    ctx.fillStyle = 'rgba(16,185,129,' + (p.life / 18) + ')';
                    ctx.font = 'bold 13px system-ui';
                    ctx.fillText(p.val, p.x, p.y);
                    if (p.life <= 0) pop.splice(i, 1);
                }

                // герой
                var hy = H - 42;
                ctx.save();
                ctx.beginPath(); ctx.arc(heroX, hy, R, 0, 7); ctx.closePath();
                ctx.fillStyle = '#e0e7ff'; ctx.fill();
                // Картинка по текущей эмоции (won/lost/neutral).
                var hImg = heroSet ? (heroSet[mood] || heroSet.neutral) : null;
                if (hImg && hImg.complete && hImg.naturalWidth) {
                    ctx.save(); ctx.beginPath(); ctx.arc(heroX, hy, R - 2, 0, 7); ctx.clip();
                    ctx.drawImage(hImg, heroX - (R - 2), hy - (R - 2), (R - 2) * 2, (R - 2) * 2);
                    ctx.restore();
                } else {
                    ctx.font = '30px system-ui';
                    ctx.fillText(mood === 'won' ? '😄' : (mood === 'lost' ? '😞' : '🧑‍💼'), heroX, hy);
                }
                // Рамка-индикатор настроения: зелёная при «поймал», красная при 💣.
                ctx.lineWidth = 2.5;
                ctx.strokeStyle = mood === 'won' ? '#10b981' : (mood === 'lost' ? '#ef4444' : '#6366f1');
                ctx.beginPath(); ctx.arc(heroX, hy, R, 0, 7); ctx.stroke();
                ctx.restore();

                // HUD
                ctx.textAlign = 'left'; ctx.fillStyle = '#374151'; ctx.font = 'bold 15px system-ui';
                ctx.fillText('🏆 ' + score, 10, 18);
                ctx.textAlign = 'right'; ctx.fillStyle = '#9ca3af'; ctx.font = '12px system-ui';
                ctx.fillText('рекорд ' + best, W - 10, 16);
                ctx.textAlign = 'left'; ctx.font = '14px system-ui';
                ctx.fillText('❤️'.repeat(Math.max(0, lives)), 10, 38);

                if (state !== 'play') {
                    ctx.fillStyle = 'rgba(15,23,42,.55)'; ctx.fillRect(0, 0, W, H);
                    ctx.textAlign = 'center'; ctx.fillStyle = '#fff';
                    ctx.font = 'bold 22px system-ui';
                    ctx.fillText(state === 'over' ? 'Игра окончена' : 'Лови заявки!', W / 2, H / 2 - 28);
                    ctx.font = '15px system-ui';
                    if (state === 'over') ctx.fillText('Счёт: ' + score + (score >= best && score > 0 ? ' · рекорд! 🎉' : ''), W / 2, H / 2 + 2);
                    else ctx.fillText(heroName ? ('Герой: ' + heroName) : 'Выбери героя выше', W / 2, H / 2 + 2);
                    ctx.font = 'bold 14px system-ui'; ctx.fillStyle = '#a5b4fc';
                    ctx.fillText('▶ нажми, чтобы ' + (state === 'over' ? 'сыграть ещё' : 'начать'), W / 2, H / 2 + 34);
                }
            }

            function loop() {
                if (state === 'play') update();
                draw();
                requestAnimationFrame(loop);
            }
            loop();
        }
    })();
    </script>
</x-guest-layout>
