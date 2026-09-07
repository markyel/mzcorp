import './bootstrap';

// ВАЖНО: Alpine НЕ импортируем и не стартуем отдельно — Livewire 3
// поставляется со своим bundle'ом Alpine. Standalone-импорт + Alpine.start()
// создавал двойной инстанс («Detected multiple instances of Alpine running»),
// из-за чего ломались:
//   - window.Livewire.find(...).entangle('open') возвращал undefined,
//   - Alpine.navigate отсутствовал (используется в $this->redirect navigate:true),
//   - @click.outside="$wire.close()" на notifications-bell ронял промис.
//
// Если понадобятся Alpine-плагины (focus / intersect / mask) — регистрировать
// их через хук document.addEventListener('alpine:init', ...) ДО старта
// Livewire, или через Livewire.start(... custom alpine ...).

// Богатый редактор письма (почтовый клиент): Alpine-компонент mailEditor.
// Регистрируем через alpine:init — Alpine поставляется с Livewire, отдельный
// инстанс не создаём (см. комментарий выше).
import mailEditor from './mail-editor';
// Регистрируем и по alpine:init, и сразу, если Alpine уже стартовал (порядок
// выполнения module-скрипта и livewire.js зависит от кеша/сети — иначе
// компонент «mailEditor is not defined», редактор не инициализируется).
const registerMailEditor = () => { if (window.Alpine && ! window.__mailEditorRegistered) { window.Alpine.data('mailEditor', mailEditor); window.__mailEditorRegistered = true; } };
document.addEventListener('alpine:init', registerMailEditor);
document.addEventListener('livewire:init', registerMailEditor);
registerMailEditor();

// Индикатор «Сохранение…» композера почты. Окно телепортировано в body, и
// Livewire не обрабатывает там wire:loading (директивы сканируются только в
// DOM компонента) — поэтому сигналим событием из хука commit.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ component, succeed, fail }) => {
        if (component.name !== 'mail.composer') return;
        const emit = (on) => window.dispatchEvent(new CustomEvent('mail-composer-saving', { detail: on }));
        emit(true);
        succeed(() => emit(false));
        fail(() => emit(false));
    });
});
