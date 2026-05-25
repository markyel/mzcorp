<div>
    @if($open)
        @php $developerName = config('support.developer_name'); @endphp
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(15,18,23,0.55); display: flex; align-items: flex-start; justify-content: center; padding: 48px 24px; overflow-y: auto;"
             wire:click.self="close">
            <div style="width: min(640px, 100%); background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--r-lg); box-shadow: 0 24px 56px -12px rgba(0,0,0,0.25); overflow: hidden;"
                 wire:click.stop>

                {{-- Banner --}}
                <div class="relative">
                    <img src="{{ asset('images/contact-creator-banner.svg') }}" alt="Связь с создателем"
                         class="block w-full h-auto select-none" draggable="false">
                    {{-- Заголовок поверх баннера --}}
                    <div class="absolute inset-0 flex flex-col justify-center px-6 pr-44">
                        <h1 class="text-[22px] font-bold text-fg-1 leading-tight m-0">Связь с создателем</h1>
                        <p class="text-[13px] text-fg-2 mt-1 m-0 max-w-[280px]">
                            Опишите проблему или предложение — постараюсь помочь лично.
                        </p>
                    </div>
                    {{-- Close --}}
                    <button type="button" wire:click="close"
                            aria-label="Закрыть"
                            class="absolute top-3 right-3 w-8 h-8 rounded-md inline-flex items-center justify-center text-fg-2 hover:text-fg-1"
                            style="background: rgba(255,255,255,0.85); border: 1px solid #f4c8ce; line-height: 1;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                {{-- Sub-row: маленькая иконка + слоган --}}
                <div class="flex items-center gap-3 px-5 pt-3 pb-2 border-b border-border-subtle">
                    <img src="{{ asset('images/contact-creator.svg') }}" alt=""
                         class="w-9 h-9 shrink-0" style="color: var(--accent);">
                    <div class="min-w-0">
                        <div class="text-[14px] font-semibold text-fg-1 leading-tight">Здесь читают и отвечают</div>
                        <div class="text-[12px] text-fg-3 leading-snug mt-px">
                            Контекст подтянется автоматически: где вы были, какая роль, какая страница.
                        </div>
                    </div>
                </div>

                @if($sentSuccess)
                    {{-- Success state --}}
                    <div class="px-5 py-5">
                        <div class="rounded-md border border-[var(--emerald-600)] bg-[var(--emerald-50,#ecfdf5)] p-4 text-[13px] text-fg-1">
                            <div class="font-semibold mb-1">Тикет #{{ $sentTicketId }} создан.</div>
                            Спасибо! Я получу уведомление и отвечу здесь же, в системе.
                        </div>
                    </div>
                    <div class="flex items-center gap-2 px-5 py-3 border-t border-border" style="background: var(--bg-surface-2, var(--bg-app));">
                        @if($sentTicketId)
                            <a href="{{ route('support.show', $sentTicketId) }}" class="btn btn-primary">Открыть тикет</a>
                        @endif
                        <a href="{{ route('support.my') }}" class="btn">Мои обращения</a>
                        <button type="button" wire:click="close" class="btn">Закрыть</button>
                    </div>
                @else
                    <form wire:submit="save">
                        <div class="px-5 py-4 space-y-3.5">

                            {{-- Тема --}}
                            <div>
                                <div class="flex items-baseline gap-2 mb-1.5">
                                    <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">Тема</span>
                                    <span class="text-[11.5px] text-fg-3">(можно пропустить)</span>
                                </div>
                                <input type="text" wire:model="subject" maxlength="200"
                                       placeholder="Кратко, чтобы я узнал в списке"
                                       class="w-full px-3 py-2 border border-[var(--border-strong)] rounded-md bg-surface text-fg-1 text-[13px] outline-none focus:border-[var(--sky-500)]"
                                       style="line-height: 1.5;">
                                @error('subject') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                            </div>

                            {{-- Описание --}}
                            <div>
                                <div class="flex items-baseline gap-2 mb-1.5">
                                    <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">Описание</span>
                                    <span class="text-[var(--accent)] text-[11px]">*</span>
                                </div>
                                <textarea wire:model="body" rows="5" maxlength="5000"
                                          placeholder="Что произошло? Что вы делали? Что ожидали увидеть?"
                                          class="w-full px-3 py-2 border border-[var(--border-strong)] rounded-md bg-surface text-fg-1 text-[13px] outline-none focus:border-[var(--sky-500)] resize-y"
                                          style="min-height: 120px; line-height: 1.5;"></textarea>
                                @error('body') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                            </div>

                            {{-- Файлы --}}
                            <div>
                                <div class="flex items-baseline gap-2 mb-1.5">
                                    <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">Файлы</span>
                                    <span class="text-[11.5px] text-fg-3">скриншоты, документы — до 10 МБ × файл</span>
                                </div>
                                <div class="flex items-center gap-2.5 flex-wrap">
                                    <label class="inline-flex items-center gap-1.5 h-8 px-3 border border-[var(--border-strong)] rounded-md bg-surface text-fg-1 text-[12.5px] font-medium cursor-pointer hover:bg-[var(--bg-hover)]">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="1.75"
                                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 17.93 8.8l-8.58 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                        </svg>
                                        Выбрать файлы
                                        <input type="file" wire:model="attachments" multiple class="hidden">
                                    </label>
                                    <span class="text-[12px] text-fg-3">
                                        @if(empty($attachments))
                                            Файл не выбран
                                        @else
                                            Выбрано: {{ count($attachments) }} {{ trans_choice('файл|файла|файлов', count($attachments)) }}
                                        @endif
                                    </span>
                                </div>

                                <div wire:loading wire:target="attachments" class="text-[12px] text-fg-3 mt-1.5">
                                    Загружаем файлы…
                                </div>
                                @error('attachments.*') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror

                                @if(!empty($attachments))
                                    <ul class="mt-2 space-y-0.5 text-[12px] text-fg-2">
                                        @foreach($attachments as $f)
                                            <li>· {{ $f->getClientOriginalName() }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            {{-- Auto-context disclosure --}}
                            @if(!empty($context['url']) || !empty($context['route_name']))
                                <details class="text-[12px] text-fg-3 border-t border-border-subtle pt-2.5">
                                    <summary class="cursor-pointer select-none text-sky-700 font-medium inline-flex items-center gap-1.5 hover:text-sky-800"
                                             style="list-style: none;">
                                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <polyline points="9 18 15 12 9 6"></polyline>
                                        </svg>
                                        Что я отправлю автоматически
                                    </summary>
                                    <div class="mt-2 p-3 rounded-md border border-border-subtle font-mono text-[11.5px] text-fg-2 leading-normal space-y-0.5"
                                         style="background: var(--bg-app);">
                                        <div><span class="text-fg-3">страница:</span> {{ $context['url'] ?? '—' }}</div>
                                        <div><span class="text-fg-3">route:</span> {{ $context['route_name'] ?: '—' }}</div>
                                        <div><span class="text-fg-3">роль:</span> {{ collect(auth()->user()?->getRoleNames() ?? [])->map(fn ($r) => \App\Enums\Role::tryFrom($r)?->label() ?? $r)->implode(', ') ?: '—' }}</div>
                                        <div><span class="text-fg-3">пользователь:</span> {{ auth()->user()?->email ?? '—' }}</div>
                                        <div><span class="text-fg-3">viewport:</span> {{ $context['viewport'] ?? '—' }}</div>
                                        <div><span class="text-fg-3">время:</span> {{ now()->format('d.m.Y H:i') }} ({{ config('app.timezone') }})</div>
                                    </div>
                                </details>
                            @endif
                        </div>

                        <div class="flex items-center gap-2 px-5 py-3 border-t border-border" style="background: var(--bg-surface-2, var(--bg-app));">
                            <button type="submit" class="btn btn-primary"
                                    wire:loading.attr="disabled" wire:target="save,attachments">
                                <span wire:loading.remove wire:target="save">Отправить</span>
                                <span wire:loading wire:target="save">Отправляем…</span>
                            </button>
                            <button type="button" wire:click="close" class="btn">Отмена</button>
                            <span class="flex-1"></span>
                            @if($developerName)
                                <span class="text-[11.5px] text-fg-3 leading-tight text-right max-w-[260px]">
                                    Отвечает {{ $developerName }}, обычно в течение дня.
                                </span>
                            @endif
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
