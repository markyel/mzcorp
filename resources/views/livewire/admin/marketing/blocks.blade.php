<div>
    <div class="mb-4 flex items-start justify-between gap-4">
        @php $above = app(\App\Services\Marketing\MarketingBlockService::class)->isAboveSignature(); @endphp
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Блок вставляется <span class="font-medium">{{ $above ? 'над подписью' : 'под подписью' }} менеджера</span> в письма клиентам, отправленные через MyLift —
            и в ручные ответы, и в авто-уведомления. Письма поставщикам и внутренние не затрагиваются.
            Из активных блоков в каждое письмо попадает <span class="font-medium">случайный</span>.
            Положение меняется в <a href="{{ route('settings.index') }}" class="text-sky-700 hover:underline">Настройках</a> → «Реклама в письмах».
        </p>
        <button type="button" wire:click="{{ $showForm ? 'cancel' : 'startCreate' }}"
                class="inline-flex items-center px-4 py-2 bg-[#D32027] hover:bg-[#A8181E] text-white text-sm font-medium rounded shadow-sm whitespace-nowrap">
            {{ $showForm ? 'Отмена' : '+ Добавить блок' }}
        </button>
    </div>

    @if($flashMessage)
        <div class="mb-3 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ $flashMessage }}</div>
    @endif
    @if($flashError)
        <div class="mb-3 p-3 rounded bg-amber-50 border border-amber-200 text-amber-800 text-sm">{{ $flashError }}</div>
    @endif

    {{-- Форма + превью --}}
    @if($showForm)
        <div class="mb-4 rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <form wire:submit.prevent="save" class="space-y-3">
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-200">
                        {{ $editId ? 'Редактирование блока' : 'Новый блок' }}
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Заголовок</label>
                        <input type="text" wire:model.live.debounce.400ms="title" maxlength="120" placeholder="Например: Новинка — платы KONE со склада"
                               class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                        @error('title') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Краткий текст <span class="text-gray-400">(до 300 символов)</span></label>
                        <textarea wire:model.live.debounce.400ms="text" rows="3" maxlength="300"
                                  class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600"></textarea>
                        @error('text') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Ссылка</label>
                        <input type="text" wire:model.live.debounce.400ms="url" placeholder="https://myzip.ru/…"
                               class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono">
                        @error('url') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 mb-1">
                            Картинка <span class="text-gray-400">(PNG/JPG, до 512 КБ; в письме показывается шириной 120 px)</span>
                        </label>
                        <div class="flex items-center gap-3">
                            <label class="inline-flex items-center px-3 py-1.5 text-xs rounded border border-gray-300 dark:border-gray-600 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700">
                                {{ $image || $this->editing?->image_path ? 'Заменить' : 'Выбрать файл' }}
                                <input type="file" wire:model.live="image" accept="image/png,image/jpeg" class="hidden">
                            </label>
                            <span wire:loading wire:target="image" class="text-xs text-gray-500">Загрузка…</span>
                            @if($image)
                                <span class="text-xs text-gray-600">{{ $image->getClientOriginalName() }}</span>
                            @elseif($this->editing?->image_path)
                                <span class="text-xs text-gray-500">текущая картинка сохранена</span>
                            @endif
                        </div>
                        @error('image') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="isActive" class="rounded border-gray-300 text-[#D32027] focus:ring-[#D32027]">
                        Активен (участвует в случайном выборе)
                    </label>

                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="cancel" class="px-4 py-2 text-sm rounded border border-gray-300 dark:border-gray-600">Отмена</button>
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-[#D32027] hover:bg-[#A8181E] text-white text-sm font-medium rounded shadow-sm">
                            Сохранить
                        </button>
                    </div>

                    {{-- Тестовая отправка --}}
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 space-y-2">
                        <div class="text-sm font-medium text-gray-800 dark:text-gray-200">Тестовая отправка</div>
                        <p class="text-xs text-gray-500">
                            Письмо с образцом текста, подписью владельца ящика и этим блоком. Сохранять блок перед тестом не обязательно.
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">С ящика</label>
                                <select wire:model.live="testMailboxId" class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                    <option value="">— выбрать —</option>
                                    @foreach($this->senders as $mb)
                                        <option value="{{ $mb->id }}">{{ $mb->email }}{{ $mb->owner ? ' · '.$mb->owner->name : '' }}</option>
                                    @endforeach
                                </select>
                                @error('testMailboxId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">На адрес</label>
                                <input type="text" wire:model="testEmail" placeholder="you@myzip.ru"
                                       class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono">
                                @error('testEmail') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest"
                                    class="inline-flex items-center px-4 py-2 text-sm font-medium rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                <span wire:loading.remove wire:target="sendTest">Отправить тест</span>
                                <span wire:loading wire:target="sendTest">Отправка…</span>
                            </button>
                        </div>
                    </div>
                </form>

                <div>
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">Превью письма</div>
                    <iframe class="w-full rounded border border-gray-200 dark:border-gray-700 bg-white" style="height:560px;"
                            wire:key="promo-preview-{{ md5($this->previewHtml) }}"
                            srcdoc="{{ $this->previewHtml }}"></iframe>
                    <p class="text-xs text-gray-500 mt-1">Подпись — владельца выбранного ящика (или ваша). Текст письма — образец.</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Список --}}
    @if($this->blocks->isEmpty())
        <div class="rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-8 text-center text-gray-500">
            Рекламных блоков нет. Пока их нет, письма уходят без рекламы.
        </div>
    @else
        <div class="overflow-x-auto rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 text-xs uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left w-20"></th>
                        <th class="px-3 py-2 text-left">Блок</th>
                        <th class="px-3 py-2 text-left">Ссылка</th>
                        <th class="px-3 py-2 text-right">Показов</th>
                        <th class="px-3 py-2 text-left">Последний</th>
                        <th class="px-3 py-2 text-left">Кто</th>
                        <th class="px-3 py-2 text-center">Активен</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($this->blocks as $block)
                        <tr wire:key="promo-{{ $block->id }}" class="{{ $block->is_active ? '' : 'opacity-60' }}">
                            <td class="px-3 py-2 align-top">
                                @if($block->imageUrl())
                                    <img src="{{ $block->imageUrl() }}" alt="" class="w-16 h-auto rounded border border-gray-200">
                                @else
                                    <span class="text-xs text-gray-400">нет</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ $block->title }}</div>
                                <div class="text-xs text-gray-600 max-w-md">{{ $block->text }}</div>
                            </td>
                            <td class="px-3 py-2 align-top font-mono text-xs max-w-xs truncate" title="{{ $block->url }}">
                                <a href="{{ $block->url }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline">{{ $block->url }}</a>
                            </td>
                            <td class="px-3 py-2 align-top text-right tabular-nums">{{ $block->impressions_count }}</td>
                            <td class="px-3 py-2 align-top text-xs text-gray-600 whitespace-nowrap">{{ $block->last_used_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="px-3 py-2 align-top text-xs text-gray-600">{{ $block->createdBy?->name ?? '—' }}</td>
                            <td class="px-3 py-2 align-top text-center">
                                <input type="checkbox" @checked($block->is_active) wire:click="toggleActive({{ $block->id }})"
                                       class="rounded border-gray-300 text-[#D32027] focus:ring-[#D32027]">
                            </td>
                            <td class="px-3 py-2 align-top text-right whitespace-nowrap">
                                <button type="button" wire:click="startEdit({{ $block->id }})" class="text-xs text-sky-700 hover:underline mr-3">Изменить</button>
                                <button type="button" wire:click="delete({{ $block->id }})"
                                        wire:confirm="Удалить блок «{{ $block->title }}»?"
                                        class="text-xs text-gray-500 hover:text-red-600">Удалить</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
