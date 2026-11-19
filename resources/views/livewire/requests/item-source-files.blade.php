{{-- Файлы письма заявки — источники позиций (Livewire Requests\ItemSourceFiles). Показываем, когда файлов два и больше:
     тогда бывает, что позиции нужны только из одного (MZ2026-02199). --}}
@php $files = $this->files; @endphp
<div>
@if($files->count() >= 2)
    <div style="margin-bottom: 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg-surface); font-size: 12.5px; padding: 8px 12px; display: flex; flex-direction: column; gap: 6px;">
        <div style="display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap;">
            <b style="color: var(--fg-1);">Где искать позиции</b>
            <span class="text-fg-3">снимите галочку с файлов, где позиций нет (уведомления, реквизиты), и перечитайте — парсер их пропустит.</span>
        </div>
        <div style="display: flex; gap: 6px 16px; flex-wrap: wrap;">
            @foreach($files as $f)
                <label wire:key="isf-{{ $f->id }}" style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; {{ $f->items_ignored_at ? 'color: var(--fg-3); text-decoration: line-through;' : '' }}"
                       title="{{ $f->items_ignored_at ? 'Не искать позиции · '.$f->items_ignored_at->format('d.m H:i') : 'Парсер ищет позиции в этом файле' }}">
                    <input type="checkbox" wire:click="toggle({{ $f->id }})" @checked($f->items_ignored_at === null)>
                    <span>{{ $f->filename }}</span>
                </label>
            @endforeach
        </div>
        @if($changed)
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <button type="button" wire:click="reparse" wire:confirm="Перечитать позиции из отмеченных файлов? Текущие позиции заявки удалятся и появятся заново — правки в них пропадут."
                        class="btn btn-sm btn-primary">Перечитать позиции</button>
                <span class="text-fg-3">позиции из снятых файлов уйдут, правки текущих позиций пропадут</span>
            </div>
        @endif
    </div>
@endif
</div>
