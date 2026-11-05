---
name: pool-infinite-scroll
description: "Пул заявок: плоский список (без группировки по статусу) + infinite scroll через x-intersect; Alpine intersect забандлен в Livewire"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Раздел «Заявки» (`App\Livewire\Requests\Pool`, /dashboard/requests), commit 0164b43.

**Список без группировки:** раньше группировал текущую страницу по статусу (sticky group-headers) — путало оператора. Теперь плоский список в порядке сортировки (дропдаун: attention/created_desc/created_asc); реальный статус виден чипом в строке. Удалены `$groups`/`$grouped`/`$groupOrder` в render(); blade рендерит один `@foreach($rows as $req)`.

**Infinite scroll вместо пагинации:** `public int $perPage = 25` (НЕ в URL) + `loadMore()` (+=25). render(): `$total = (clone $query)->count(); $rows = $query->take($this->perPage)->get(); $hasMore = $rows->count() < $total`. Blade: sentinel `<div x-intersect.margin.300px="$wire.loadMore()">` со спиннером (`wire:loading wire:target="loadMore"`), рендерится только при `$hasMore`. Футер «Показано N из M». `resetPage()` ПЕРЕОПРЕДЕЛЁН (override метода трейта WithPagination) → сбрасывает perPage в 25; все updating*-хуки и action'ы уже зовут resetPage, поэтому любая смена фильтра скроллит в начало. `WithPagination` оставлен (override шадоует его resetPage; paginate()/links() больше не используются).

**ГРАБЛЯ (durable):** `x-intersect` (Alpine intersect plugin) **доступен из коробки** — Livewire 3 бандлит Alpine С intersect (проверено: `directive("intersect"` в vendor/livewire/livewire/dist/livewire.min.js). Комментарий в resources/js/app.js намекает обратное («если понадобятся плагины focus/intersect/mask — регистрировать») — но intersect уже есть, регистрировать не нужно. Проект использует встроенный Alpine Livewire (свой Alpine НЕ импортируем — двойной инстанс ломает entangle/navigate).

**ГРАБЛЯ (scroll anchoring, 2026-06-30):** после loadMore вьюпорт прыгал в КОНЕЦ нового списка из 50 (новые строки оказывались ВЫШЕ экрана, листать к ним надо было ВВЕРХ). Причина — браузерный scroll anchoring: новые строки вставляются между последней строкой и sentinel'ом; браузер держит «якорь» (sentinel в зоне видимости) и доскролливает scrollTop на высоту добавленного. Фикс ч.1: `style="overflow-anchor: none"` на `.flex-1.overflow-auto` (commit a114429) — починил ПЛАВНЫЙ скролл, но при долистывании в САМЫЙ низ всё равно прыгало. Причина-2: на pool скроллит САМО ОКНО (внутренний overflow-div не настоящий скроллер — pool.blade root `min-height`, не fixed; коммент в blade «основная страница скроллится естественно»), momentum утаскивал в конец нового списка. Фикс ч.2 (надёжный, commit f279bac): захват+восстановление scrollTop вокруг loadMore — sentinel `x-data` с методом `keepScrollLoadMore()`: находит реальный скролл-контейнер (идёт вверх по overflowY auto/scroll, fallback `document.scrollingElement`), `const y=s.scrollTop; this.$wire.loadMore().then(()=>requestAnimationFrame(()=>s.scrollTop=y))`. Работает независимо от того, что скроллит. Keying thead/sentinel (wire:key) НЕ помогло (морфинг был ни при чём — порядок выборки стабилен, проверено take(25)==take(50)[:25]); оставлено как гигиена. Урок: «новые элементы появляются не там» при догрузке = чаще scroll anchoring, а не morph.

**ГРАБЛЯ (инцидент 2026-06-30, 500):** переменную списка во view назвали `$rows`, а ниже в `render()` (ветка `canSeeAll`, per-manager счётчики) было `$rows = Request::query()->selectRaw('assigned_user_id, COUNT(*)...')->groupBy(...)->get()` — агрегатные строки БЕЗ id затирали список перед `'rows' => $rows`. Во view `route('requests.show', $req)` падал «Missing parameter [request]» → 500 ТОЛЬКО у привилегированных (canSeeAll), у менеджеров ок. Фикс: переименовать агрегат в `$mgrRows` (commit 59a776c). Урок: проверять коллизии имени выходной переменной по ВСЕМУ render(), не только рядом.

Грабли проверки CSS на проде: класс в собранном CSS экранируется (`h-\[44px\]`), поэтому `grep "h-\[44px\]"` (regex) даёт ложный MISSING — проверять `grep -F "h-\[44px\]"`. Новый arbitrary-класс требует `npm run build` на проде (см. [[tailwind-arbitrary-classes]], [[prod-deploy]]).
