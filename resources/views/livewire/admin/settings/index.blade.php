<div class="max-w-[900px] mx-auto px-6 pt-3 pb-8">

    <div class="mb-4">
        <h1 class="text-[20px] font-semibold text-fg-1">Настройки приложения</h1>
        <p class="text-[12.5px] text-fg-3 mt-1">
            Override поверх <code class="mono">config()</code>. Если значение совпадает с дефолтом
            из <code class="mono">.env</code> — override удаляется (возвращается к defaults).
            Изменения вступают в силу сразу, без <code class="mono">queue:restart</code>.
        </p>
    </div>

    @if(session('settings-flash'))
        <div class="ds-card mb-3 border-emerald-300">
            <div class="px-[18px] py-2.5 text-[12.5px] text-emerald-800 bg-emerald-50">
                {{ session('settings-flash') }}
            </div>
        </div>
    @endif

    <form wire:submit.prevent="save">
        @foreach($this->grouped as $group => $items)
            <div class="ds-card mb-3">
                <div class="ds-card-header">
                    <h3>{{ $group }}</h3>
                    <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ count($items) }}</span>
                </div>
                <div class="divide-y divide-[var(--border-subtle)]">
                    @foreach($items as $key => $meta)
                        <div class="px-[18px] py-3 grid items-start gap-4" style="grid-template-columns: 1fr 220px">
                            <div class="min-w-0">
                                <label for="setting-{{ $key }}" class="text-[13px] text-fg-1 font-medium">{{ $meta['label'] }}</label>
                                <div class="text-[11.5px] text-fg-3 mt-1 leading-snug">{{ $meta['help'] }}</div>
                                <div class="text-[10.5px] text-fg-3 mt-1 mono">{{ $key }}</div>
                            </div>
                            <div class="flex items-center justify-end">
                                @switch($meta['type'])
                                    @case('bool')
                                        <label class="inline-flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox"
                                                   id="setting-{{ $key }}"
                                                   wire:model="values.{{ $key }}"
                                                   class="rounded border-border focus:ring-sky-500 text-sky-700">
                                            <span class="text-[12.5px] text-fg-1">{{ $values[$key] ? 'включено' : 'выключено' }}</span>
                                        </label>
                                        @break
                                    @case('int')
                                    @case('float')
                                        <input type="number"
                                               id="setting-{{ $key }}"
                                               wire:model="values.{{ $key }}"
                                               step="{{ $meta['step'] ?? ($meta['type'] === 'float' ? '0.01' : '1') }}"
                                               @isset($meta['min']) min="{{ $meta['min'] }}" @endisset
                                               @isset($meta['max']) max="{{ $meta['max'] }}" @endisset
                                               class="w-full px-2.5 py-1.5 border border-border rounded-md text-[13px] mono focus:outline-none focus:ring-1 focus:ring-sky-500 focus:border-sky-500">
                                        @break
                                    @default
                                        @if(! empty($meta['options']))
                                            <select id="setting-{{ $key }}"
                                                    wire:model="values.{{ $key }}"
                                                    class="w-full px-2.5 py-1.5 border border-border rounded-md text-[13px] focus:outline-none focus:ring-1 focus:ring-sky-500">
                                                @foreach($meta['options'] as $optVal => $optLabel)
                                                    <option value="{{ $optVal }}">{{ $optLabel }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input type="text"
                                                   id="setting-{{ $key }}"
                                                   wire:model="values.{{ $key }}"
                                                   class="w-full px-2.5 py-1.5 border border-border rounded-md text-[13px] focus:outline-none focus:ring-1 focus:ring-sky-500">
                                        @endif
                                @endswitch
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="flex items-center justify-end gap-2 mt-4">
            <span class="text-[11.5px] text-fg-3 flex-1">
                Сохранение применяется сразу. Если значение = config-default → override удаляется.
            </span>
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="btn btn-primary"
                    wire:target="save">
                <span wire:loading.remove wire:target="save">Сохранить</span>
                <span wire:loading wire:target="save">Сохраняю...</span>
            </button>
        </div>
    </form>

</div>
