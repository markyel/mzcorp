<div class="space-y-4">
    @php
        $inputCls = 'h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500';
        $matrix = is_array($supplier->assortment_matrix) ? $supplier->assortment_matrix : [];
        $mBrands = $matrix['brands'] ?? [];
        $mCats = $matrix['categories'] ?? [];
        $mPairs = $matrix['pairs'] ?? [];
    @endphp

    {{-- Заголовок --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('suppliers.index', ['tab' => 'registry']) }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">← Поставщики</a>
        <h2 class="text-[16px] font-semibold text-fg-1">{{ $supplier->name ?: ($supplier->email ?: $supplier->domain) }}</h2>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Реквизиты + ассортимент --}}
        <div class="lg:col-span-2 ds-card">
            <div class="ds-card-header"><h3>Поставщик</h3></div>
            <div class="ds-card-body space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-[11.5px] text-fg-3 mb-1">Название</label>
                        <input type="text" wire:model="name" class="{{ $inputCls }}">
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">E-mail</label>
                        <input type="email" wire:model="email" class="{{ $inputCls }} mono">
                        @error('email') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Домен</label>
                        <input type="text" wire:model="domain" placeholder="supplier.ru" class="{{ $inputCls }} mono">
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Телефон</label>
                        <input type="text" wire:model="phone" class="{{ $inputCls }} mono">
                    </div>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Описание ассортимента <span class="text-fg-4">(бренды, типы запчастей — свободным текстом)</span></label>
                    <textarea wire:model="assortment_description" rows="4" placeholder="Напр.: Возим запчасти KONE, OTIS, Schindler — лебёдки, двери кабины, частотные преобразователи, платы управления." class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Заметки</label>
                    <textarea wire:model="notes" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div class="flex gap-2 pt-1">
                    <button type="button" wire:click="save" class="btn btn-sm btn-primary">Сохранить</button>
                    <button type="button" wire:click="rebuildMatrix" wire:loading.attr="disabled" class="btn btn-sm">
                        <span wire:loading.remove wire:target="rebuildMatrix">↻ Пересобрать матрицу</span>
                        <span wire:loading wire:target="rebuildMatrix">Собираю…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Матрица ассортимента --}}
        <div class="ds-card">
            <div class="ds-card-header">
                <h3>Матрица</h3>
                <span class="text-[12px] text-fg-3 ml-2">для подбора под позицию</span>
            </div>
            <div class="ds-card-body space-y-3 text-[12.5px]">
                @if(empty($mBrands) && empty($mCats) && empty($mPairs))
                    <div class="text-fg-3">Матрица не собрана. Заполните описание ассортимента и нажмите «Пересобрать матрицу».</div>
                @else
                    @if(!empty($mBrands))
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Бренды</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach($mBrands as $b)<span class="chip chip-neutral text-[11px]">{{ $b }}</span>@endforeach
                            </div>
                        </div>
                    @endif
                    @if(!empty($mCats))
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Категории</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach($mCats as $c)<span class="chip chip-sky text-[11px]">{{ $c }}</span>@endforeach
                            </div>
                        </div>
                    @endif
                    @if(!empty($mPairs))
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Пары бренд × категория</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach($mPairs as $p)<span class="chip chip-info text-[11px]">{{ $p['brand'] ?? '' }} · {{ $p['category'] ?? '' }}</span>@endforeach
                            </div>
                        </div>
                    @endif
                    @if($supplier->matrix_built_at)
                        <div class="text-[11px] text-fg-4 pt-1 border-t border-border-subtle">Собрана {{ $supplier->matrix_built_at->format('d.m.Y H:i') }} · {{ $supplier->matrix_built_with_model ?: '—' }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    {{-- Правила подбора с wildcard «ВСЕ» (ручные, приоритетны) --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Правила подбора</h3>
            <span class="text-[12px] text-fg-3 ml-2">бренд × категория, «ВСЕ» = любой</span>
        </div>
        <div class="ds-card-body space-y-3">
            <div class="text-[12px] text-fg-3">
                Точное правило поверх авто-матрицы. Примеры: <b>Schneider</b> × <b>ВСЕ</b> — любое оборудование Schneider; <b>ВСЕ</b> × <b>Ролик</b> — ролики любых марок; <b>ВСЕ</b> × <b>ВСЕ</b> — все запросы.
            </div>

            @if(!empty($rules))
                <div class="flex flex-wrap gap-1.5">
                    @foreach($rules as $idx => $r)
                        <span class="inline-flex items-center gap-1.5 chip chip-sky text-[11.5px]">
                            <span class="font-medium">{{ $r['brand'] ?? 'ВСЕ' }}</span>
                            <span class="text-fg-4">×</span>
                            <span class="font-medium">{{ $r['category'] ?? 'ВСЕ' }}</span>
                            <button type="button" wire:click="removeRule({{ $idx }})" class="text-red-600 ml-0.5" title="Удалить правило">×</button>
                        </span>
                    @endforeach
                </div>
            @else
                <div class="text-[12px] text-fg-4">Правил нет.</div>
            @endif

            <div class="flex flex-wrap items-end gap-2 border-t border-border-subtle pt-3">
                <div>
                    <label class="block text-[11px] text-fg-3 mb-1">Бренд</label>
                    <select wire:model="newRuleBrand" class="{{ $inputCls }} min-w-[180px]">
                        @foreach($this->brandOptions as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] text-fg-3 mb-1">Категория</label>
                    <select wire:model="newRuleCategory" class="{{ $inputCls }} min-w-[220px]">
                        @foreach($this->categoryOptions as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select>
                </div>
                <button type="button" wire:click="addRule" class="btn btn-sm btn-primary">Добавить правило</button>
            </div>
        </div>
    </div>

    {{-- Удаление --}}
    <div class="ds-card">
        <div class="ds-card-body flex items-center justify-between gap-3 flex-wrap">
            <div class="text-[12px] text-fg-3">Удалить поставщика из реестра. Запросы и переписка не удаляются.</div>
            @if($confirmingDelete)
                <div class="flex items-center gap-2">
                    <span class="text-[12px] text-red-700">Точно удалить?</span>
                    <button type="button" wire:click="deleteSupplier" class="btn btn-sm" style="background:var(--red-600,#dc2626);color:#fff">Удалить</button>
                    <button type="button" wire:click="$set('confirmingDelete', false)" class="btn btn-sm">Отмена</button>
                </div>
            @else
                <button type="button" wire:click="$set('confirmingDelete', true)" class="btn btn-sm text-red-600">Удалить</button>
            @endif
        </div>
    </div>
</div>
