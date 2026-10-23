{{-- Раздел «Фото»: пачка фото → очистка фона (Clipping Magic) → архив с теми же именами; фото позиций — в каталог. --}}
@php
    $draft = $this->draft;
    $items = $draft->items()->with('catalogItem:id,sku,name')->orderBy('original_name')->get();
    $acc = $this->account;
    $cm = app(\App\Services\Photos\ClippingMagicClient::class);
    $plural = fn (int $n, string $one, string $few, string $many) => $n % 10 === 1 && $n % 100 !== 11 ? $one : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? $few : $many);
    $statusLabels = ['queued' => 'в очереди', 'processing' => 'обрабатывается', 'done' => 'готово', 'failed' => 'не вышло'];
@endphp
<div class="ph" @if($this->inWork) wire:poll.3s @endif>
    <style>
        .ph{max-width:1100px;margin:0 auto;padding:24px 16px 48px;display:flex;flex-direction:column;gap:20px}
        .ph-card{border:1px solid var(--border);border-radius:10px;background:var(--bg-surface)}
        .ph-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:12px 16px;border-bottom:1px solid var(--border-subtle)}
        .ph-head h2{margin:0;font-size:15px;font-weight:600;color:var(--fg-1)}
        .ph-hint{font-size:12px;color:var(--fg-3)}
        .ph-drop{margin:16px;border:1.5px dashed var(--border-strong);border-radius:10px;padding:28px 16px;text-align:center;cursor:pointer;transition:background 120ms,border-color 120ms}
        .ph-drop.over{background:var(--bg-selected);border-color:var(--sky-500)}
        .ph-row{display:grid;grid-template-columns:minmax(0,1.3fr) 90px minmax(0,2fr) 28px;gap:12px;align-items:center;padding:7px 16px;border-top:1px solid var(--border-subtle);font-size:13px}
        .ph-mono{font-family:var(--font-mono, ui-monospace, monospace);font-size:12.5px}
        .ph-pill{display:inline-flex;align-items:center;height:20px;padding:0 8px;border-radius:999px;font-size:11.5px;font-weight:500;white-space:nowrap}
        .ph-pill.ok{background:var(--status-ok-bg);color:var(--status-ok-fg)}
        .ph-pill.warn{background:var(--status-attn-bg);color:var(--status-attn-fg)}
        .ph-pill.neutral{background:var(--bg-surface-2);color:var(--fg-2)}
        .ph-bar{height:6px;border-radius:999px;background:var(--bg-surface-2);overflow:hidden}
        .ph-bar > div{height:100%;background:var(--emerald-600);transition:width 300ms}
        .ph-thumb{width:44px;height:44px;border-radius:6px;border:1px solid var(--border);object-fit:contain;background:#fff;cursor:zoom-in}
    </style>

    <div>
        <h1 style="margin: 0; font-size: 22px; font-weight: 600; color: var(--fg-1)">Фото без фона</h1>
        <div class="ph-hint" style="margin-top: 4px">Загрузите пачку фото или zip-архив — имя файла = M-артикул позиции (M12345.jpg). Фон заменится на белый, архив вернётся с теми же именами (в .jpg). Фото позиции сохранится в каталог как «фото без водяного знака»; файлы с хвостом в имени (M12345_2.jpg) — только в архив.</div>
        @if(! $cm->isConfigured())
            <div class="ph-pill warn" style="margin-top: 8px; height: auto; padding: 6px 10px; border-radius: 6px">Не задан ключ API Clipping Magic — обработка недоступна. Ключ вносит администратор в настройки сервера.</div>
        @else
            <div class="ph-hint" style="margin-top: 6px">
                Clipping Magic: @if($acc) {{ $acc['plan'] ?? '—' }} · {{ $acc['state'] ?? '—' }} · кредитов: <b style="color: var(--fg-1)">{{ $acc['credits'] ?? '—' }}</b> @else нет связи с сервисом @endif
                @if($cm->testMode()) · <span style="color: var(--amber-700)">тестовый режим: результат с водяным знаком сервиса, в каталог не сохраняется</span>@endif
            </div>
        @endif
    </div>

    {{-- Новая пачка. --}}
    <section class="ph-card"
             x-data="{
                over: false, total: 0, done: 0, busy: false, errors: [],
                stage: '',
                {{-- Zip распаковываем в браузере (JSZip) и грузим фото по одному: размер архива не упирается в лимит загрузки. --}}
                async jszip() {
                    if (! window.JSZip) {
                        await new Promise((ok, fail) => { const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js'; s.onload = ok; s.onerror = fail; document.head.appendChild(s); });
                    }
                    return window.JSZip;
                },
                async unzip(file) {
                    const JSZip = await this.jszip();
                    {{-- Имена из Windows-архивов бывают в CP866: не UTF-8 — читаем как ibm866. --}}
                    const decode = bytes => { try { return new TextDecoder('utf-8', { fatal: true }).decode(bytes); } catch (e) { return new TextDecoder('ibm866').decode(bytes); } };
                    const zip = await JSZip.loadAsync(file, { decodeFileName: decode });
                    const out = [];
                    for (const entry of Object.values(zip.files)) {
                        const name = entry.name.split('/').pop();
                        if (entry.dir || entry.name.startsWith('__MACOSX/') || name.startsWith('.') || ! /\.(jpe?g|png|webp|bmp|gif|tiff?)$/i.test(name)) continue;
                        out.push(new File([await entry.async('blob')], name));
                    }
                    return out;
                },
                async send(files) {
                    const picked = [...files].filter(f => /\.(jpe?g|png|webp|bmp|gif|tiff?|zip)$/i.test(f.name));
                    if (! picked.length) return;
                    this.busy = true; this.done = 0; this.total = 0; this.errors = [];
                    const list = [];
                    for (const f of picked) {
                        if (! /\.zip$/i.test(f.name)) { list.push(f); continue; }
                        this.stage = 'Распаковываю ' + f.name + '…';
                        try { list.push(...await this.unzip(f)); } catch (e) { this.errors.push(f.name + ' (не распаковался)'); }
                    }
                    this.stage = '';
                    this.total = list.length;
                    for (const f of list) {
                        await new Promise(resolve => $wire.upload('upload', f, () => resolve(), () => { this.errors.push(f.name); resolve(); }));
                        this.done++;
                    }
                    this.busy = false;
                }
             }">
        <div class="ph-head">
            <h2>Новая пачка</h2>
            <span class="ph-hint">{{ $items->count() }} {{ $plural($items->count(), 'фото', 'фото', 'фото') }}</span>
            @if($items->isNotEmpty())
                <button type="button" wire:click="clearDraft" wire:confirm="Убрать все фото из новой пачки?" class="btn btn-sm" style="margin-left: auto">Очистить</button>
                <button type="button" wire:click="start" wire:loading.attr="disabled" wire:target="start" class="btn btn-sm btn-primary" @disabled(! $cm->isConfigured())>Убрать фон · {{ $items->count() }}</button>
            @endif
        </div>

        <label class="ph-drop" :class="over && 'over'"
               x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false"
               x-on:drop.prevent="over = false; send($event.dataTransfer.files)">
            <input type="file" multiple accept="image/*,.zip" class="hidden" style="display: none" x-on:change="send($event.target.files); $event.target.value = ''">
            <div style="font-size: 14px; color: var(--fg-1); font-weight: 500" x-show="! busy">Перетащите фото или zip сюда или нажмите, чтобы выбрать</div>
            <div class="ph-hint" x-show="! busy" style="margin-top: 4px">jpg, png, webp, bmp, gif, tiff или zip любого размера · фото до 29 МБ</div>
            <div x-show="busy" x-cloak style="max-width: 360px; margin: 0 auto">
                <div style="font-size: 13px; color: var(--fg-1); margin-bottom: 6px" x-text="stage || ('Загружаю ' + done + ' из ' + total + '…')"></div>
                <div class="ph-bar"><div :style="'width:' + (total ? Math.round(done / total * 100) : 0) + '%'"></div></div>
            </div>
        </label>
        <div x-show="errors.length" x-cloak class="ph-hint" style="padding: 0 16px 12px; color: var(--red-700)">Не загрузились: <span x-text="errors.join(', ')"></span></div>
        @error('upload')<div class="ph-hint" style="padding: 0 16px 12px; color: var(--red-700)">{{ $message }}</div>@enderror

        @foreach($items as $it)
            <div class="ph-row" wire:key="phd-{{ $it->id }}">
                <span class="ph-mono" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap" title="{{ $it->original_name }}">{{ $it->original_name }}</span>
                <span class="ph-mono">{{ $it->sku ?? '—' }}</span>
                <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                    @if($it->catalogItem)
                        @if($it->sku_exact)<span class="ph-pill ok">в каталог</span>@else<span class="ph-pill neutral" title="Имя с хвостом — доп. фото, только в архив">доп. фото</span>@endif
                        <span style="color: var(--fg-2)">{{ $it->catalogItem->name }}</span>
                    @elseif($it->sku)
                        <span class="ph-pill warn">нет в каталоге</span> <span class="ph-hint">только в архив</span>
                    @else
                        <span class="ph-pill warn">имя не артикул</span> <span class="ph-hint">только в архив</span>
                    @endif
                </span>
                <button type="button" wire:click="removeItem({{ $it->id }})" class="ph-hint" title="Убрать из пачки" style="font-size: 16px; line-height: 1">×</button>
            </div>
        @endforeach
    </section>

    @if($notice)
        <div class="ph-pill ok" style="height: auto; padding: 8px 12px; border-radius: 6px; font-size: 13px">{{ $notice }}</div>
    @endif

    {{-- Пачки в работе и готовые. --}}
    @if($this->batches->isNotEmpty())
        <section class="ph-card">
            <div class="ph-head"><h2>Мои пачки</h2><span class="ph-hint">последние 20</span></div>
            @foreach($this->batches as $b)
                @php
                    $pct = $b->total > 0 ? round(($b->processed + $b->failed) / $b->total * 100) : 0;
                    $failedItems = $b->failed > 0 ? $b->items()->where('status', 'failed')->orderBy('original_name')->limit(10)->get(['id', 'original_name', 'error']) : collect();
                    $thumbs = $b->status === 'done' ? $b->items()->where('status', 'done')->orderBy('original_name')->limit(12)->get(['id', 'original_name']) : collect();
                    $gallery = $thumbs->map(fn ($t) => ['src' => route('photos.item', $t->id), 'name' => $t->resultName(), 'dl' => route('photos.item', $t->id)])->values()->all();
                @endphp
                <div wire:key="phb-{{ $b->id }}" style="padding: 12px 16px; border-top: 1px solid var(--border-subtle); display: flex; flex-direction: column; gap: 8px">
                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; font-size: 13px">
                        <b style="color: var(--fg-1)">Пачка {{ $b->id }}</b>
                        <span class="ph-hint">{{ $b->created_at->timezone(config('app.timezone'))->format('d.m H:i') }} · {{ $b->total }} фото</span>
                        <span class="ph-pill {{ $b->status === 'done' ? 'ok' : ($b->status === 'failed' ? 'warn' : 'neutral') }}">{{ $statusLabels[$b->status] ?? $b->status }}</span>
                        @if($b->test)<span class="ph-pill warn">тест — с водяным знаком</span>@endif
                        <span class="ph-hint">готово {{ $b->processed }}@if($b->failed) · не вышло {{ $b->failed }}@endif</span>
                        <span style="margin-left: auto; display: inline-flex; gap: 8px">
                            @if($b->failed > 0 && ! $b->inWork())
                                <button type="button" wire:click="retry({{ $b->id }})" class="btn btn-sm">Повторить неудавшиеся</button>
                            @endif
                            @if($b->archive_path)
                                <a href="{{ route('photos.archive', $b->id) }}" class="btn btn-sm btn-primary" style="text-decoration: none">Скачать архив</a>
                            @endif
                        </span>
                    </div>
                    @if($b->inWork())
                        <div class="ph-bar" style="max-width: 420px"><div style="width: {{ $pct }}%"></div></div>
                    @endif
                    @if($thumbs->isNotEmpty())
                        <div style="display: flex; flex-wrap: wrap; gap: 6px">
                            @foreach($thumbs as $i => $t)
                                <img src="{{ route('photos.item', $t->id) }}" alt="" loading="lazy" class="ph-thumb" title="{{ $t->resultName() }}"
                                     x-on:click="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $gallery, 'index' => $i]) }})">
                            @endforeach
                            @if($b->processed > $thumbs->count())<span class="ph-hint" style="align-self: center">и ещё {{ $b->processed - $thumbs->count() }} — в архиве</span>@endif
                        </div>
                    @endif
                    @foreach($failedItems as $f)
                        <div class="ph-hint" style="color: var(--red-700)"><span class="ph-mono">{{ $f->original_name }}</span> — {{ $f->error }}</div>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endif
</div>
