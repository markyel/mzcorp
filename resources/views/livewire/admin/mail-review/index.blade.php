<div class="space-y-4">
    {{-- Header + window/search filters --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Авто-отклонённые письма</h3>
            <span class="text-[12px] text-fg-3 ml-2">AI пометил как НЕ-заявку — пересмотрите и реоткройте ошибочно отклонённые</span>
            <span class="flex-1"></span>
            <input type="search"
                   wire:model.live.debounce.350ms="search"
                   placeholder="subject / from…"
                   class="h-[30px] px-2.5 border border-border rounded-md text-[12.5px] outline-none focus:border-[var(--sky-500)] min-w-[200px]" />
        </div>

        <div class="px-4 pb-3 flex items-center gap-1.5 flex-wrap text-[12.5px]">
            <span class="text-fg-3 uppercase tracking-wider text-[10.5px] font-semibold mr-1">Период:</span>
            @php
                $windows = [
                    'today' => 'Сегодня',
                    '7d' => '7 дн.',
                    '30d' => '30 дн.',
                    '90d' => '90 дн.',
                    'all' => 'Всё',
                ];
            @endphp
            @foreach($windows as $key => $label)
                @php $on = $window === $key; @endphp
                <button type="button" wire:click="setWindow('{{ $key }}')"
                        class="h-[26px] px-2.5 rounded-md whitespace-nowrap font-medium
                               {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-[var(--bg-surface)] border border-[var(--border-strong)] text-[var(--fg-2)] hover:text-[var(--fg-1)]' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- List --}}
    <div class="ds-card">
        @php $emails = $this->emails; @endphp
        @if($emails->isEmpty())
            <div class="p-12 text-center text-fg-3">
                @if($search !== '')
                    Ничего не нашли по запросу «{{ $search }}».
                @else
                    В этом периоде — пусто. AI всё корректно отсортировал.
                @endif
            </div>
        @else
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-4 py-2 text-left">От / Тема</th>
                        <th class="px-4 py-2 text-left">
                            <button type="button" wire:click="toggleSort"
                                    class="inline-flex items-center gap-1 uppercase tracking-wider text-[10.5px] font-semibold text-fg-3 hover:text-fg-1"
                                    title="{{ $sort === 'newest' ? 'Сначала новые — нажмите для старых' : 'Сначала старые — нажмите для новых' }}">
                                Дата
                                <span aria-hidden="true">{{ $sort === 'newest' ? '↓' : '↑' }}</span>
                            </button>
                        </th>
                        <th class="px-4 py-2 text-left">Причина AI</th>
                        <th class="px-4 py-2 text-left">Влож.</th>
                        <th class="px-4 py-2 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($emails as $em)
                        @php
                            $artifacts = is_array($em->detected_artifacts ?? null) ? $em->detected_artifacts : [];
                            $alreadyConfirmed = collect($artifacts)
                                ->contains(fn ($a) => ($a['type'] ?? null) === 'manual_confirm_rejection');
                            $bodyPreview = mb_substr(trim((string) ($em->body_plain ?? '')), 0, 200);
                            $reasoning = trim((string) ($em->category_reasoning ?? ''));
                        @endphp
                        <tr wire:key="em-{{ $em->id }}" class="border-b border-border-subtle last:border-b-0 hover:bg-hover {{ $alreadyConfirmed ? 'opacity-65' : '' }}">
                            <td class="px-4 py-2 max-w-[400px]">
                                <div class="font-medium text-fg-1 truncate" title="{{ $em->subject }}">{{ $em->subject ?: '(без темы)' }}</div>
                                <div class="text-fg-3 text-[11.5px] truncate">{{ $em->from_name ? $em->from_name . ' · ' : '' }}<span class="mono">{{ $em->from_email }}</span></div>
                                @if($bodyPreview !== '')
                                    <div class="text-fg-4 text-[11px] mt-0.5 line-clamp-2" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">{{ $bodyPreview }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2 mono text-[11px] text-fg-2 whitespace-nowrap">
                                {{ $em->sent_at?->format('d.m.Y H:i') ?: '—' }}
                            </td>
                            <td class="px-4 py-2 max-w-[260px]">
                                @if($reasoning !== '')
                                    <div class="text-fg-3 text-[11px] line-clamp-3" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;" title="{{ $reasoning }}">{{ $reasoning }}</div>
                                @else
                                    <span class="text-fg-4 text-[11px]">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 mono text-fg-3 text-[12px]">
                                @if($em->attachments_count > 0)
                                    📎 {{ $em->attachments_count }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button type="button"
                                        wire:click="reopenAsRequest({{ $em->id }})"
                                        wire:confirm="Создать заявку из этого письма? AI-решение «не заявка» будет перезаписано вручную."
                                        class="btn btn-sm btn-primary">↻ Это заявка</button>
                                @if(! $alreadyConfirmed)
                                    <button type="button"
                                            wire:click="confirmRejection({{ $em->id }})"
                                            class="btn btn-sm" title="Подтвердить AI-решение «отклонить»">✓ Согласен</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($emails->hasPages())
                <div class="px-4 py-3 border-t border-border-subtle">{{ $emails->links() }}</div>
            @endif
        @endif
    </div>
</div>
