<div class="space-y-4">
    {{-- Header + filters --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Почта</h3>
            <span class="text-[12px] text-fg-3 ml-2">Все письма всех ящиков (read-only)</span>
            <span class="flex-1"></span>
            <span class="text-[11.5px] text-fg-3 mono">{{ $this->emails->total() }} писем за период</span>
        </div>

        <div class="px-4 pb-3 flex items-center gap-2 flex-wrap text-[12.5px]">
            {{-- Ящик --}}
            <span class="text-fg-3 uppercase tracking-wider text-[10.5px] font-semibold">Ящик:</span>
            <select wire:model.live="mailboxId"
                    class="h-[26px] px-2 border border-border rounded-md bg-surface text-fg-1 text-[12px] outline-none focus:border-[var(--sky-500)] max-w-[260px]">
                <option value="">Все ящики</option>
                @foreach($this->mailboxesForFilter as $mb)
                    <option value="{{ $mb->id }}">
                        {{ $mb->email }}
                        @if($mb->type?->value === 'personal' && $mb->owner) · {{ $mb->owner->name }}@endif
                        @if(! $mb->is_active) · ⏸ inactive @endif
                    </option>
                @endforeach
            </select>

            <span class="w-px h-5 bg-border mx-1"></span>

            {{-- Направление --}}
            <span class="text-fg-3 uppercase tracking-wider text-[10.5px] font-semibold">Направление:</span>
            @php
                $directions = ['' => 'Все', 'inbound' => 'Входящие', 'outbound' => 'Исходящие'];
            @endphp
            @foreach($directions as $k => $label)
                @php $on = $direction === $k; @endphp
                <button type="button" wire:click="setDirection('{{ $k }}')"
                        class="h-[26px] px-2.5 rounded-md whitespace-nowrap font-medium
                               {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface border border-border-strong text-fg-2 hover:text-fg-1' }}">
                    {{ $label }}
                </button>
            @endforeach

            <span class="w-px h-5 bg-border mx-1"></span>

            {{-- Период --}}
            <span class="text-fg-3 uppercase tracking-wider text-[10.5px] font-semibold">Период:</span>
            @php
                $periods = ['today' => 'Сегодня', '7d' => '7 дн.', '30d' => '30 дн.', '90d' => '90 дн.', 'all' => 'Всё'];
            @endphp
            @foreach($periods as $k => $label)
                @php $on = $period === $k; @endphp
                <button type="button" wire:click="setPeriod('{{ $k }}')"
                        class="h-[26px] px-2.5 rounded-md whitespace-nowrap font-medium
                               {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface border border-border-strong text-fg-2 hover:text-fg-1' }}">
                    {{ $label }}
                </button>
            @endforeach

            <span class="w-px h-5 bg-border mx-1"></span>

            {{-- Привязка к заявке --}}
            <span class="text-fg-3 uppercase tracking-wider text-[10.5px] font-semibold">Заявка:</span>
            @php
                $linkages = ['all' => 'Все', 'linked' => 'С заявкой', 'unlinked' => 'Без заявки'];
            @endphp
            @foreach($linkages as $k => $label)
                @php $on = $linkage === $k; @endphp
                <button type="button" wire:click="setLinkage('{{ $k }}')"
                        class="h-[26px] px-2.5 rounded-md whitespace-nowrap font-medium
                               {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface border border-border-strong text-fg-2 hover:text-fg-1' }}">
                    {{ $label }}
                </button>
            @endforeach

            <span class="w-px h-5 bg-border mx-1"></span>

            {{-- Cross-mailbox копии --}}
            <button type="button" wire:click="toggleShowCopies"
                    class="h-[26px] px-2.5 rounded-md whitespace-nowrap font-medium
                           {{ $showCopies ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface border border-border-strong text-fg-2 hover:text-fg-1' }}"
                    title="Показывать копии писем в личных ящиках менеджеров (DeliverToManagerInboxJob)">
                {{ $showCopies ? '☑' : '☐' }} копии
            </button>
        </div>
    </div>

    {{-- List --}}
    <div class="ds-card">
        @php $emails = $this->emails; @endphp
        @if($emails->isEmpty())
            <div class="p-12 text-center text-fg-3">
                В этом периоде ничего не найдено. Попробуй сменить фильтры или расширить период.
            </div>
        @else
            {{-- overflow-x-auto на случай если экран уже min-width таблицы (1320px).
                 Без этого 8 фиксированных колонок просто обрезаются по правому краю. --}}
            <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]" style="min-width: 1320px">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-3 py-2 text-left w-[100px]">Дата</th>
                        <th class="px-3 py-2 text-left w-[240px]">От → Кому</th>
                        <th class="px-3 py-2 text-left">Тема</th>
                        <th class="px-3 py-2 text-left w-[200px]">Ящик</th>
                        <th class="px-3 py-2 text-center w-[60px]">Влож.</th>
                        <th class="px-3 py-2 text-left w-[130px]">Категория</th>
                        <th class="px-3 py-2 text-left w-[140px]">Заявка</th>
                        <th class="px-3 py-2 text-center w-[34px]"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($emails as $em)
                        @php
                            $isExpanded = $expandedId === $em->id;
                            $isInbound = $em->direction?->value === 'inbound';
                            $when = $em->sent_at ?? $em->created_at;

                            // From → To кратко.
                            $fromText = $em->from_name ? $em->from_name : ($em->from_email ?: '—');
                            $toList = is_array($em->to_recipients) ? $em->to_recipients : [];
                            $firstTo = $toList[0]['email'] ?? $toList[0]['name'] ?? null;
                            $extraTo = max(count($toList) - 1, 0);

                            $catLabel = $this->categoryLabel($em->category);
                            $catClass = $this->categoryChipClass($em->category);

                            $req = $em->relatedRequest;
                            $mb = $em->mailbox;
                        @endphp
                        <tr wire:key="email-row-{{ $em->id }}"
                            wire:click="toggleExpand({{ $em->id }})"
                            class="border-b border-border-subtle last:border-b-0 hover:bg-hover cursor-pointer
                                   {{ $isExpanded ? 'bg-surface-2' : '' }}">
                            <td class="px-3 py-2 mono text-[11px] text-fg-2 whitespace-nowrap align-top">
                                {{ $when?->format('d.m.Y') ?? '—' }}<br>
                                <span class="text-fg-4">{{ $when?->format('H:i') ?? '' }}</span>
                            </td>
                            <td class="px-3 py-2 max-w-[280px] align-top">
                                <div class="flex items-center gap-1.5 text-[12px]">
                                    @if($isInbound)
                                        <span class="text-fg-3 text-[10px]">↘</span>
                                    @else
                                        <span class="text-fg-3 text-[10px]">↗</span>
                                    @endif
                                    <span class="font-medium text-fg-1 truncate" title="{{ $em->from_email }}">{{ $fromText }}</span>
                                </div>
                                <div class="text-fg-4 text-[11px] mono truncate" title="{{ $em->from_email }}">
                                    @if($isInbound)
                                        {{ $em->from_email }}
                                    @elseif($firstTo)
                                        → {{ $firstTo }}@if($extraTo > 0) <span class="text-fg-4">+{{ $extraTo }}</span>@endif
                                    @else
                                        —
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2 max-w-[420px] align-top">
                                <div class="text-fg-1 truncate" title="{{ $em->subject }}">
                                    {{ $em->subject ?: '(без темы)' }}
                                </div>
                            </td>
                            <td class="px-3 py-2 text-[11.5px] text-fg-2 align-top">
                                @if($mb)
                                    <div class="truncate mono" title="{{ $mb->email }}">{{ $mb->email }}</div>
                                    @if($mb->owner)
                                        <div class="text-fg-4 text-[10.5px] truncate">{{ $mb->owner->name }}</div>
                                    @endif
                                @else
                                    <span class="text-fg-4">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center text-[12px] mono text-fg-3 align-top">
                                @if($em->attachments_count > 0)
                                    📎 {{ $em->attachments_count }}
                                @else
                                    <span class="text-fg-4">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if($em->category)
                                    <span class="chip {{ $catClass }}"><span class="dot"></span>{{ $catLabel }}</span>
                                @else
                                    <span class="text-fg-4 text-[11px]">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if($req)
                                    <a href="{{ route('requests.show', $req->id) }}"
                                       wire:navigate
                                       onclick="event.stopPropagation()"
                                       class="chip chip-info hover:opacity-80">
                                        <span class="dot"></span>{{ $req->internal_code ?? '#'.$req->id }}
                                    </a>
                                @else
                                    <span class="text-fg-4 text-[11px]">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center align-top">
                                <span class="text-fg-3 text-[12px]">
                                    {{ $isExpanded ? '▲' : '▼' }}
                                </span>
                            </td>
                        </tr>

                        {{-- Expanded row --}}
                        @if($isExpanded)
                            @php $full = $this->expandedEmail; @endphp
                            <tr wire:key="email-expand-{{ $em->id }}" class="bg-surface-2 border-b border-border">
                                <td colspan="8" class="p-0">
                                    @if(! $full)
                                        <div class="px-6 py-4 text-fg-3">Загрузка…</div>
                                    @else
                                        <div class="px-6 py-4 space-y-3">
                                            {{-- Header: From / To / Cc / Subject / Mailbox --}}
                                            <div class="grid grid-cols-[110px_1fr] gap-x-3 gap-y-1 text-[12px]">
                                                <div class="text-fg-3">Тема:</div>
                                                <div class="text-fg-1 font-medium">{{ $full->subject ?: '(без темы)' }}</div>

                                                <div class="text-fg-3">От:</div>
                                                <div class="text-fg-1 mono">
                                                    @if($full->from_name) {{ $full->from_name }} @endif
                                                    &lt;{{ $full->from_email }}&gt;
                                                </div>

                                                @php $to = is_array($full->to_recipients) ? $full->to_recipients : []; @endphp
                                                @if(! empty($to))
                                                    <div class="text-fg-3">Кому:</div>
                                                    <div class="text-fg-1 mono">
                                                        @foreach($to as $i => $r)
                                                            @if($i > 0), @endif
                                                            @if(! empty($r['name'])) {{ $r['name'] }} @endif
                                                            &lt;{{ $r['email'] ?? '?' }}&gt;
                                                        @endforeach
                                                    </div>
                                                @endif

                                                @php $cc = is_array($full->cc_recipients) ? $full->cc_recipients : []; @endphp
                                                @if(! empty($cc))
                                                    <div class="text-fg-3">Копия:</div>
                                                    <div class="text-fg-1 mono">
                                                        @foreach($cc as $i => $r)
                                                            @if($i > 0), @endif
                                                            @if(! empty($r['name'])) {{ $r['name'] }} @endif
                                                            &lt;{{ $r['email'] ?? '?' }}&gt;
                                                        @endforeach
                                                    </div>
                                                @endif

                                                <div class="text-fg-3">Через:</div>
                                                <div class="text-fg-2 mono">
                                                    {{ $full->mailbox?->email ?? '—' }}
                                                    @if($full->folder) · {{ $full->folder }}@endif
                                                </div>

                                                <div class="text-fg-3">Когда:</div>
                                                <div class="text-fg-2 mono">
                                                    {{ ($full->sent_at ?? $full->created_at)?->format('d.m.Y H:i') ?? '—' }}
                                                </div>
                                            </div>

                                            {{-- Body --}}
                                            @php $html = $this->bodyHtmlFor($full); @endphp
                                            <div class="mt-3 border-t border-border pt-3 text-[13px] leading-[1.55] text-fg-1">
                                                @if($html)
                                                    <iframe
                                                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                                        srcdoc="{{ $html }}"
                                                        loading="lazy"
                                                        class="w-full block border-0 bg-surface rounded-md"
                                                        style="height: 0"
                                                        x-data
                                                        x-init="
                                                            const fit = () => {
                                                                try {
                                                                    const h = $el.contentDocument && $el.contentDocument.documentElement
                                                                        ? $el.contentDocument.documentElement.scrollHeight
                                                                        : 0;
                                                                    $el.style.height = (h + 4) + 'px';
                                                                } catch (e) {}
                                                            };
                                                            $el.addEventListener('load', () => {
                                                                try {
                                                                    const doc = $el.contentDocument;
                                                                    if (!doc) return;
                                                                    doc.querySelectorAll('a[href]').forEach(a => {
                                                                        a.target = '_blank';
                                                                        a.rel = 'noopener noreferrer';
                                                                    });
                                                                    const s = doc.createElement('style');
                                                                    s.textContent = 'html,body{margin:0;padding:0}body{padding:8px 12px;font:13px/1.55 system-ui,-apple-system,Segoe UI,Inter,sans-serif;color:#0a0a0a;word-break:break-word}img{max-width:100%;height:auto}';
                                                                    (doc.head || doc.documentElement).appendChild(s);
                                                                    try { new ResizeObserver(fit).observe(doc.documentElement); } catch (e) {}
                                                                    fit();
                                                                } catch (e) {}
                                                            });
                                                        "
                                                    ></iframe>
                                                @elseif($full->body_plain)
                                                    <pre class="whitespace-pre-wrap font-sans text-[13px] bg-surface rounded-md p-3 border border-border">{{ $full->body_plain }}</pre>
                                                @else
                                                    <div class="text-fg-3">(пустое тело)</div>
                                                @endif
                                            </div>

                                            {{-- Attachments --}}
                                            @if($full->attachments->isNotEmpty())
                                                <div class="border-t border-border pt-3">
                                                    <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-2">
                                                        Вложения · {{ $full->attachments->count() }}
                                                    </div>
                                                    <div class="flex flex-wrap gap-2">
                                                        @foreach($full->attachments as $att)
                                                            @php
                                                                $isImg = $att->mime_type && str_starts_with($att->mime_type, 'image/');
                                                                $previewUrl = route('attachments.preview', $att);
                                                                $downloadUrl = route('attachments.download', $att);
                                                            @endphp
                                                            @if($isImg)
                                                                <a href="{{ $previewUrl }}"
                                                                   target="_blank"
                                                                   rel="noopener"
                                                                   onclick="event.stopPropagation()"
                                                                   class="block border border-border rounded-md overflow-hidden bg-surface hover:border-border-strong transition-colors text-left"
                                                                   title="{{ $att->filename }}">
                                                                    <img src="{{ $previewUrl }}"
                                                                         alt="{{ $att->filename }}"
                                                                         loading="lazy"
                                                                         class="w-[140px] h-[100px] object-cover block bg-app">
                                                                    <div class="px-2 py-1 max-w-[140px] text-[11px] text-fg-3">
                                                                        <span class="block truncate text-fg-1">{{ $att->filename }}</span>
                                                                        @if($att->size_bytes)
                                                                            <span>{{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                                        @endif
                                                                    </div>
                                                                </a>
                                                            @else
                                                                <a href="{{ $downloadUrl }}"
                                                                   onclick="event.stopPropagation()"
                                                                   class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px] text-fg-1 hover:bg-hover">
                                                                    <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">
                                                                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::afterLast($att->filename, '.')) ?: 'BIN' }}
                                                                    </span>
                                                                    <span class="truncate max-w-[280px]">{{ $att->filename }}</span>
                                                                    @if($att->size_bytes)
                                                                        <span class="text-fg-3 text-[11px]">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                                    @endif
                                                                </a>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Link to Request --}}
                                            @if($full->relatedRequest)
                                                <div class="border-t border-border pt-3 flex items-center gap-2">
                                                    <span class="text-fg-3 text-[12px]">Привязано к заявке:</span>
                                                    <a href="{{ route('requests.show', $full->relatedRequest->id) }}"
                                                       wire:navigate
                                                       onclick="event.stopPropagation()"
                                                       class="chip chip-info hover:opacity-80">
                                                        <span class="dot"></span>{{ $full->relatedRequest->internal_code }}
                                                    </a>
                                                    <span class="text-fg-4 text-[11px]">↗ открыть карточку</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
            </div>{{-- /overflow-x-auto --}}

            @if($emails->hasPages())
                <div class="px-4 py-3 border-t border-border-subtle">{{ $emails->links() }}</div>
            @endif
        @endif
    </div>
</div>
