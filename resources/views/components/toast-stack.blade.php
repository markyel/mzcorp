{{--
    Глобальный стек тостов. Подключён один раз в layouts/app.blade.php.

    Livewire-компоненты шлют:
        $this->dispatch('toast', message: 'Создан черновик M-2026-…', type: 'success');
    Livewire 4 диспатчит это как window CustomEvent('toast') с detail = {message, type}.
    Из Alpine/JS можно так же: $dispatch('toast', { message: '…', type: 'error' }).

    type: success | error | warning | info. Без type или с неизвестным — info.
    Необязательно: href — тост становится ссылкой; duration — время показа в мс.
    Скрытие: ~5 с, error/warning — ~8 с; наведение мыши останавливает таймер.
    Одинаковый тост, уже висящий на экране, не дублируется — перезапускается таймер.
    Стили — .toast-* в resources/css/app.css (токены status-*).
--}}
@persist('toast-stack')
<div x-data="{
        toasts: [],
        seq: 0,
        max: 5,
        durations: { success: 5000, info: 5000, warning: 8000, error: 8000 },
        aliases: { danger: 'error', warn: 'warning', ok: 'success' },
        normalize(detail) {
            let d = detail;
            // Позиционный dispatch('toast', 'текст') приходит массивом.
            if (Array.isArray(d)) d = (d[0] && typeof d[0] === 'object') ? d[0] : { message: d[0], type: d[1] };
            if (typeof d === 'string') d = { message: d };
            if (! d || typeof d !== 'object') return null;
            const message = String(d.message ?? '').trim();
            if (message === '') return null;
            let type = String(d.type ?? '').toLowerCase();
            type = this.aliases[type] ?? type;
            if (! (type in this.durations)) type = 'info';
            // href — тост-ссылка (клик ведёт по адресу), duration — своё время показа, мс.
            const href = typeof d.href === 'string' && d.href !== '' ? d.href : null;
            const duration = Number(d.duration) > 0 ? Number(d.duration) : null;
            return { message, type, href, duration };
        },
        push(detail) {
            const n = this.normalize(detail);
            if (! n) return;
            // gone — тост уже закрывается (или закрыт); visible — флаг x-show для
            // анимации появления, включается на следующем тике.
            const same = this.toasts.find(t => ! t.gone && t.message === n.message && t.type === n.type);
            if (same) { this.arm(same); return; }
            const live = this.toasts.filter(x => ! x.gone);
            if (live.length >= this.max) live.slice(0, live.length - this.max + 1).forEach(x => this.dismiss(x.id));
            this.toasts.push({ id: ++this.seq, ...n, visible: false, gone: false, timer: null });
            const t = this.toasts[this.toasts.length - 1];
            this.arm(t);
            this.$nextTick(() => { if (! t.gone) t.visible = true; });
        },
        find(id) { return this.toasts.find(t => t.id === id); },
        arm(t) {
            clearTimeout(t.timer);
            t.timer = setTimeout(() => this.dismiss(t.id), t.duration ?? this.durations[t.type]);
        },
        hold(t) { clearTimeout(t.timer); },
        dismiss(id) {
            const t = this.find(id);
            if (! t || t.gone) return;
            clearTimeout(t.timer);
            t.gone = true;
            t.visible = false;
            setTimeout(() => { this.toasts = this.toasts.filter(x => x.id !== id); }, 200);
        },
    }"
     x-on:toast.window="push($event.detail)"
     class="toast-stack"
     aria-live="polite">
    <template x-for="t in toasts" :key="t.id">
        <div x-show="t.visible"
             x-transition:enter="toast-anim"
             x-transition:enter-start="toast-anim-from"
             x-transition:leave="toast-anim"
             x-transition:leave-end="toast-anim-from"
             x-on:mouseenter="hold(t)"
             x-on:mouseleave="t.gone || arm(t)"
             class="toast"
             :class="'toast-' + t.type"
             :role="t.type === 'error' ? 'alert' : 'status'">
            <span class="toast-icon" aria-hidden="true">
                {{-- Lucide outline, stroke 1.5 --}}
                <svg x-show="t.type === 'success'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <svg x-show="t.type === 'error'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                <svg x-show="t.type === 'warning'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                <svg x-show="t.type === 'info'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            </span>
            <template x-if="t.href">
                <a class="toast-message toast-link" :href="t.href" x-text="t.message"></a>
            </template>
            <template x-if="! t.href">
                <span class="toast-message" x-text="t.message"></span>
            </template>
            <button type="button" class="toast-close" x-on:click="dismiss(t.id)" aria-label="Закрыть" title="Закрыть">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
    </template>
</div>
@endpersist
