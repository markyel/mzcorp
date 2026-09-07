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
document.addEventListener('alpine:init', () => {
    window.Alpine.data('mailEditor', mailEditor);
});
