<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[560px]" wire:click.stop>
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="text-[15px] font-semibold text-fg-1">
                            Связь с создателем
                        </h3>
                        <p class="text-[12px] text-fg-3 mt-0.5">
                            Опишите проблему или предложение. Мы получим контекст автоматически: где вы были, какая роль, какая страница.
                        </p>
                    </div>
                    <button type="button" wire:click="close"
                            class="text-fg-3 hover:text-fg-1 text-[18px] leading-none px-2 -mr-2 -mt-1">&times;</button>
                </div>

                @if($sentSuccess)
                    <div class="space-y-3">
                        <div class="rounded-md border border-[var(--emerald-600)] bg-[var(--emerald-50,#ecfdf5)] p-3 text-[13px] text-fg-1">
                            <div class="font-semibold mb-1">Тикет #{{ $sentTicketId }} создан.</div>
                            Спасибо! Я получу уведомление и отвечу здесь же, в системе.
                        </div>
                        <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                            @if($sentTicketId)
                                <a href="{{ route('support.show', $sentTicketId) }}"
                                   class="btn btn-primary">Открыть тикет</a>
                            @endif
                            <a href="{{ route('support.my') }}" class="btn">Мои обращения</a>
                            <button type="button" wire:click="close" class="btn">Закрыть</button>
                        </div>
                    </div>
                @else
                    <form wire:submit="save" class="space-y-3">
                        <div>
                            <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                                Тема <span class="normal-case text-fg-4 font-normal">(можно пропустить)</span>
                            </label>
                            <input type="text" wire:model="subject" maxlength="200"
                                   placeholder="Кратко, чтобы я узнал в списке"
                                   class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                            @error('subject') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                                Описание <span class="text-[var(--red-600)]">*</span>
                            </label>
                            <textarea wire:model="body" rows="6" maxlength="5000"
                                      placeholder="Что произошло? Что вы делали? Что ожидали увидеть?"
                                      class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-y"></textarea>
                            @error('body') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                                Файлы <span class="normal-case text-fg-4 font-normal">(скриншоты, документы — до 10 МБ × файл)</span>
                            </label>
                            <input type="file" wire:model="attachments" multiple
                                   class="block w-full text-[12.5px] text-fg-2
                                          file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border file:border-border
                                          file:bg-surface file:text-fg-1 file:text-[12.5px] file:font-medium
                                          file:hover:bg-[var(--bg-hover)] file:cursor-pointer" />

                            <div wire:loading wire:target="attachments" class="text-[12px] text-fg-3 mt-1">
                                Загружаем файлы…
                            </div>
                            @error('attachments.*') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror

                            @if(!empty($attachments))
                                <ul class="mt-2 space-y-0.5 text-[12px] text-fg-2">
                                    @foreach($attachments as $i => $f)
                                        <li>· {{ $f->getClientOriginalName() }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        @if(!empty($context['url']) || !empty($context['route_name']))
                            <details class="text-[12px] text-fg-3">
                                <summary class="cursor-pointer select-none hover:text-fg-2">
                                    Что я отправлю автоматически
                                </summary>
                                <ul class="mt-1.5 pl-3 space-y-0.5 font-mono text-[11.5px]">
                                    <li>URL: {{ $context['url'] ?? '—' }}</li>
                                    <li>Route: {{ $context['route_name'] ?? '—' }}</li>
                                    <li>Viewport: {{ $context['viewport'] ?? '—' }}</li>
                                    <li>User-Agent: {{ \Illuminate\Support\Str::limit($context['user_agent'] ?? '—', 80) }}</li>
                                </ul>
                            </details>
                        @endif

                        <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                            <button type="submit" class="btn btn-primary"
                                    wire:loading.attr="disabled" wire:target="save,attachments">
                                <span wire:loading.remove wire:target="save">Отправить</span>
                                <span wire:loading wire:target="save">Отправляем…</span>
                            </button>
                            <button type="button" wire:click="close" class="btn">Отмена</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
