{{-- ────────── LIGHTBOX (просмотр картинок) ──────────
     Переиспользуемый лайтбокс. Открывается событием window:open-image с detail:
       - legacy: {src, name, dl} — одиночная картинка;
       - gallery: {items: [{src,name,dl},...], index: N} — листаемая.
     Закрытие: Esc, клик по бэкдропу, кнопка «Закрыть».
     Навигация: ← / → (клавиатура и кнопки), wrap-around.
     Подключается @include('partials.image-lightbox') — используется и в
     клиентском треде (detail), и в переписке с поставщиком (suppliers/show). --}}
<div x-data="{
        lbOpen: false,
        lbItems: [],
        lbIdx: 0,
        lbSrc: '',
        lbName: '',
        lbDl: '',
        sync() {
            const it = this.lbItems[this.lbIdx] || { src: '', name: '', dl: '' };
            this.lbSrc = it.src || '';
            this.lbName = it.name || '';
            this.lbDl = it.dl || '';
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
     }"
     x-on:open-image.window="open($event.detail)"
     x-on:keydown.escape.window="lbOpen = false"
     x-on:keydown.left.window="if (lbOpen) prev()"
     x-on:keydown.right.window="if (lbOpen) next()">
    <div x-show="lbOpen"
         x-transition.opacity.duration.150ms
         style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.82); cursor: zoom-out;"
         x-on:click.self="lbOpen = false">
        <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; align-items: center; gap: 8px; z-index: 2;">
            <span style="color: rgba(255,255,255,0.92); font-size: 12px; font-family: var(--font-mono); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <span x-text="lbName"></span>
                <span x-show="lbItems.length > 1" style="opacity: 0.7;"> · <span x-text="lbIdx + 1"></span> / <span x-text="lbItems.length"></span></span>
            </span>
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
             style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: calc(100vw - 120px); max-height: calc(100vh - 80px); width: auto; height: auto; object-fit: contain; cursor: default; display: block; box-shadow: 0 8px 32px rgba(0,0,0,0.5);"
             x-on:click.stop>
    </div>
</div>
