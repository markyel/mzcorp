{{-- ────────── LIGHTBOX (просмотр картинок) ──────────
     Переиспользуемый лайтбокс. Открывается событием window:open-image с detail:
       - legacy: {src, name, dl} — одиночная картинка;
       - gallery: {items: [{src,name,dl},...], index: N} — листаемая.
     Закрытие: Esc, клик по бэкдропу, кнопка «Закрыть».
     Навигация: ← / → (клавиатура и кнопки), wrap-around.
     Масштаб: колесо мыши (к курсору), кнопки ± , двойной клик, +/−/0.
     Поворот: кнопки ⟲ ⟳ и клавиша R — фото с телефона часто приходят лежащими.
     Увеличенную картинку можно таскать мышью.
     Подключается @include('partials.image-lightbox') — используется и в
     клиентском треде (detail), и в переписке с поставщиком (suppliers/show). --}}
<div x-data="{
        lbOpen: false,
        lbItems: [],
        lbIdx: 0,
        lbSrc: '',
        lbName: '',
        lbDl: '',
        zoom: 1,
        rot: 0,
        x: 0,
        y: 0,
        drag: null,
        moved: false,
        maxZoom: 8,
        reset() { this.zoom = 1; this.rot = 0; this.x = 0; this.y = 0; },
        sync() {
            const it = this.lbItems[this.lbIdx] || { src: '', name: '', dl: '' };
            this.lbSrc = it.src || '';
            this.lbName = it.name || '';
            this.lbDl = it.dl || '';
            // Соседнее фото открывается как новое: чужой масштаб и поворот на
            // нём — не подсказка, а помеха.
            this.reset();
        },
        open(detail) {
            if (detail && Array.isArray(detail.items) && detail.items.length > 0) {
                this.lbItems = detail.items.slice();
                this.lbIdx = Math.max(0, Math.min(parseInt(detail.index) || 0, this.lbItems.length - 1));
            } else if (detail) {
                this.lbItems = [{ src: detail.src, name: detail.name, dl: detail.dl }];
                this.lbIdx = 0;
            } else {
                return;
            }
            this.sync();
            this.lbOpen = true;
        },
        prev() {
            if (this.lbItems.length > 1) {
                this.lbIdx = (this.lbIdx - 1 + this.lbItems.length) % this.lbItems.length;
                this.sync();
            }
        },
        next() {
            if (this.lbItems.length > 1) {
                this.lbIdx = (this.lbIdx + 1) % this.lbItems.length;
                this.sync();
            }
        },
        /* Масштабирование к точке: под курсором остаётся та же точка снимка —
           иначе разглядывать маркировку на детали невозможно, картинка уезжает.
           cx/cy — курсор от центра экрана, в тех же координатах живёт сдвиг. */
        zoomAt(factor, cx, cy) {
            const z = Math.min(this.maxZoom, Math.max(1, this.zoom * factor));
            const k = z / this.zoom;
            if (k === 1) { return; }
            this.x = cx - k * (cx - this.x);
            this.y = cy - k * (cy - this.y);
            this.zoom = z;
            if (z === 1) { this.x = 0; this.y = 0; }
        },
        zoomBy(factor) { this.zoomAt(factor, 0, 0); },
        onWheel(e) {
            this.zoomAt(e.deltaY < 0 ? 1.2 : 1 / 1.2,
                e.clientX - window.innerWidth / 2,
                e.clientY - window.innerHeight / 2);
        },
        onDblClick(e) {
            if (this.zoom > 1) { this.zoom = 1; this.x = 0; this.y = 0; return; }
            this.zoomAt(2.5, e.clientX - window.innerWidth / 2, e.clientY - window.innerHeight / 2);
        },
        rotate(dir) {
            this.rot = (this.rot + dir * 90 + 360) % 360;
            this.x = 0;
            this.y = 0;
        },
        startDrag(e) {
            if (this.zoom <= 1) { return; }
            this.drag = { x: e.clientX - this.x, y: e.clientY - this.y };
            this.moved = false;
        },
        onDrag(e) {
            if (! this.drag) { return; }
            this.x = e.clientX - this.drag.x;
            this.y = e.clientY - this.drag.y;
            this.moved = true;
        },
        endDrag() { this.drag = null; },
        /* Клик по фону закрывает — но не тогда, когда это конец перетаскивания
           увеличенной картинки: палец увёл её за край, и окно захлопывалось. */
        backdrop() {
            if (this.moved) { this.moved = false; return; }
            this.lbOpen = false;
        },
        onKey(e) {
            if (! this.lbOpen) { return; }
            if (e.key === '+' || e.key === '=') { this.zoomBy(1.25); }
            else if (e.key === '-' || e.key === '_') { this.zoomBy(1 / 1.25); }
            else if (e.key === '0') { this.reset(); }
            else if (e.key === 'r' || e.key === 'R' || e.key === 'к' || e.key === 'К') { this.rotate(e.shiftKey ? -1 : 1); }
            else { return; }
            e.preventDefault();
        },
        /* Повёрнутая на бок картинка вписывается в экран другой стороной. */
        fit() {
            const across = this.rot % 180 !== 0;
            return {
                maxWidth: across ? 'calc(100vh - 96px)' : 'calc(100vw - 120px)',
                maxHeight: across ? 'calc(100vw - 120px)' : 'calc(100vh - 96px)',
            };
        },
     }"
     x-on:open-image.window="open($event.detail)"
     x-on:keydown.escape.window="lbOpen = false"
     x-on:keydown.left.window="if (lbOpen) prev()"
     x-on:keydown.right.window="if (lbOpen) next()"
     x-on:keydown.window="onKey($event)">
    <div x-show="lbOpen"
         x-transition.opacity.duration.150ms
         style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.82); cursor: zoom-out; overflow: hidden;"
         x-on:click.self="backdrop()"
         x-on:wheel.prevent="onWheel($event)"
         x-on:pointermove="onDrag($event)"
         x-on:pointerup.window="endDrag()">
        <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; align-items: center; gap: 8px; z-index: 2;">
            <span style="color: rgba(255,255,255,0.92); font-size: 12px; font-family: var(--font-mono); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <span x-text="lbName"></span>
                <span x-show="lbItems.length > 1" style="opacity: 0.7;"> · <span x-text="lbIdx + 1"></span> / <span x-text="lbItems.length"></span></span>
            </span>

            {{-- Масштаб и поворот. Кнопки на тёмном фоне — свои, не .btn: белый
                 прямоугольник поверх снимка мешает смотреть. --}}
            <div style="display: flex; align-items: center; gap: 4px;" x-on:click.stop>
                <button type="button" class="lb-tool" title="Мельче (−)" x-on:click="zoomBy(1 / 1.25)">−</button>
                <span style="min-width: 44px; text-align: center; color: rgba(255,255,255,0.8); font: 500 11.5px/1 var(--font-mono);"
                      x-text="Math.round(zoom * 100) + '%'"></span>
                <button type="button" class="lb-tool" title="Крупнее (+)" x-on:click="zoomBy(1.25)">+</button>
                <button type="button" class="lb-tool" title="Повернуть влево (Shift+R)" x-on:click="rotate(-1)">⟲</button>
                <button type="button" class="lb-tool" title="Повернуть вправо (R)" x-on:click="rotate(1)">⟳</button>
                <button type="button" class="lb-tool" title="Как было (0)"
                        x-show="zoom !== 1 || rot !== 0" x-on:click="reset()">Сбросить</button>
            </div>

            <a :href="lbDl" download class="btn btn-sm" x-on:click.stop>Скачать</a>
            <button type="button" class="btn btn-sm" x-on:click.stop="lbOpen = false">Закрыть</button>
        </div>
        {{-- Prev / Next кнопки. Скрыты если в галерее всего 1 картинка. --}}
        <button type="button"
                x-show="lbItems.length > 1"
                x-on:click.stop="prev()"
                title="Предыдущее (←)"
                style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); z-index: 2; width: 44px; height: 44px; border-radius: 50%; border: none; background: rgba(255,255,255,0.12); color: white; font-size: 22px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
            ‹
        </button>
        <button type="button"
                x-show="lbItems.length > 1"
                x-on:click.stop="next()"
                title="Следующее (→)"
                style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%); z-index: 2; width: 44px; height: 44px; border-radius: 50%; border: none; background: rgba(255,255,255,0.12); color: white; font-size: 22px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
            ›
        </button>
        <img :src="lbSrc" :alt="lbName"
             draggable="false"
             :style="Object.assign({
                 transform: 'translate(-50%, -50%) translate(' + x + 'px, ' + y + 'px) scale(' + zoom + ') rotate(' + rot + 'deg)',
                 cursor: zoom > 1 ? (drag ? 'grabbing' : 'grab') : 'zoom-in',
                 // Под мышью картинка должна идти за курсором без отставания;
                 // плавность нужна только кнопкам и колесу.
                 transition: drag ? 'none' : 'transform .12s ease-out',
             }, fit())"
             style="position: absolute; top: 50%; left: 50%; width: auto; height: auto; object-fit: contain; display: block; box-shadow: 0 8px 32px rgba(0,0,0,0.5); will-change: transform; user-select: none;"
             x-on:click.stop
             x-on:dblclick.stop="onDblClick($event)"
             x-on:pointerdown.prevent="startDrag($event)">
    </div>
</div>

<style>
    .lb-tool {
        height: 26px;
        min-width: 26px;
        padding: 0 7px;
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 6px;
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.92);
        font: 500 13px/1 var(--font-sans);
        cursor: pointer;
    }

    .lb-tool:hover {
        background: rgba(255, 255, 255, 0.2);
    }
</style>
