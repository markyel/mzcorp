<div class="space-y-4">
    {{-- Header + window/search filters --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Автозакрытые заявки</h3>
            <span class="text-[12px] text-fg-3 ml-2">AI-фильтр (gpt-4o-mini) посчитал письмо «нет данных для заявки» и закрыл. Восстановите ошибочно закрытые.</span>
            <span class="flex-1"></span>
            <input type="search"
                   wire:model.live.debounce.350ms="search"
                   placeholder="код / subject / from…"
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
            <span class="ml-auto text-fg-3 text-[11.5px]">Всего в выборке: <span class="font-semibold text-fg-1">{{ $this->totalCount }}</span></span>
        </div>
    </div>

    {{-- List --}}
    <div class="ds-card">
        @php
            $requests = $this->requests;
            $llmByReq = $this->llmPayloadByRequestId;
        @endphp
        @if($requests->isEmpty())
            <div class="p-12 text-center text-fg-3">
                @if($search !== '')
                    Ничего не нашли по запросу «{{ $search }}».
                @else
                    В этом периоде — пусто. LLM ничего не закрывал автоматически.
                @endif
            </div>
        @else
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-4 py-2 text-left">Код / От / Тема</th>
                        <th class="px-4 py-2 text-left">Создана</th>
                        <th class="px-4 py-2 text-left">Reasoning LLM</th>
                        <th class="px-4 py-2 text-left">Влож.</th>
                        <th class="px-4 py-2 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requests as $req)
                        @php
                            $em = $req->emailMessage;
                            $bodyPreview = mb_substr(trim((string) ($em->body_plain ?? '')), 0, 180);
                            $llm = $llmByReq[$req->id] ?? [];
                            $reasoning = (string) ($llm['reasoning'] ?? '');
                            $confidence = $llm['confidence'] ?? null;
                            $confPct = is_numeric($confidence) ? (int) round($confidence * 100) : null;
                            $attCount = $em ? $em->attachments->count() : 0;
                        @endphp
                        <tr wire:key="r-{{ $req->id }}" class="border-b border-border-subtle last:border-b-0 hover:bg-hover">
                            <td class="px-4 py-2 max-w-[400px]">
                                <div class="flex items-baseline gap-2">
                                    <a href="{{ route('requests.show', $req) }}" class="mono text-[11.5px] text-sky-700 hover:underline">{{ $req->internal_code }}</a>
                                    @if($confPct !== null)
                                        <span class="text-[10px] mono text-fg-4">{{ $confPct }}%</span>
                                    @endif
                                </div>
                                <div class="font-medium text-fg-1 truncate" title="{{ $em?->subject ?? $req->subject }}">{{ $em?->subject ?: $req->subject ?: '(без темы)' }}</div>
                                <div class="text-fg-3 text-[11.5px] truncate">{{ $em?->from_name ? $em->from_name . ' · ' : '' }}<span class="mono">{{ $em?->from_email ?? $req->client_email }}</span></div>
                                @if($bodyPreview !== '')
                                    <div class="text-fg-4 text-[11px] mt-0.5 line-clamp-2" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">{{ $bodyPreview }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2 mono text-[11px] text-fg-2 whitespace-nowrap">
                                {{ $req->created_at?->format('d.m.Y H:i') ?: '—' }}
                                <div class="text-fg-4 text-[10px]">закрыто: {{ $req->closed_at?->format('d.m H:i') ?? '—' }}</div>
                            </td>
                            <td class="px-4 py-2 max-w-[300px]">
                                @if($reasoning !== '')
                                    <div class="text-fg-3 text-[11px] line-clamp-3" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;" title="{{ $reasoning }}">{{ $reasoning }}</div>
                                @else
                                    <span class="text-fg-4 text-[11px] italic">— (legacy: до LLM-проверки)</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 mono text-fg-3 text-[12px]">
                                @if($attCount > 0)
                                    📎 {{ $attCount }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button type="button"
                                        wire:click="restore({{ $req->id }})"
                                        wire:confirm="Восстановить {{ $req->internal_code }} и назначить менеджеру?"
                                        class="btn btn-sm btn-primary">↻ Восстановить</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($requests->hasPages())
                <div class="px-4 py-3 border-t border-border-subtle">{{ $requests->links() }}</div>
            @endif
        @endif
    </div>
</div>
