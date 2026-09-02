<div>
@if($open)
<div class="mail-composer" wire:key="composer-{{ $draftId }}">
<style>
.mail-composer{position:fixed;right:24px;bottom:0;width:560px;max-width:calc(100vw - 48px);height:520px;max-height:calc(100vh - 80px);
    background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--r-lg) var(--r-lg) 0 0;
    box-shadow:0 -8px 32px -4px rgba(15,18,23,.22),0 -2px 8px -2px rgba(15,18,23,.1);
    display:flex;flex-direction:column;overflow:hidden;z-index:60;font-family:var(--font-sans);color:var(--fg-1)}
.mail-composer *{box-sizing:border-box}
.mail-composer .chd{display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--neutral-900);color:#fff}
.mail-composer .chd .ttl{font:600 12.5px/1 var(--font-sans);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mail-composer .chd .wbtn{width:24px;height:24px;border:none;background:none;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;color:rgba(255,255,255,.75);cursor:pointer;font-size:14px}
.mail-composer .chd .wbtn:hover{background:rgba(255,255,255,.14);color:#fff}
.mail-composer .cfields{padding:0 14px}
.mail-composer .crow{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-subtle);font-size:12.5px}
.mail-composer .crow .k{width:52px;color:var(--fg-3);font-weight:500;flex-shrink:0}
.mail-composer .crow input{flex:1;border:none;outline:none;background:transparent;font:400 13px/1.3 var(--font-sans);color:var(--fg-1);min-width:60px}
.mail-composer .crow .from{font:500 12.5px/1 var(--font-sans);color:var(--fg-1);display:flex;align-items:center;gap:6px}
.mail-composer .crow .from .dot{width:6px;height:6px;border-radius:999px;background:var(--emerald-600)}
.mail-composer .reqbadge{font:600 10.5px/1.4 var(--font-mono);background:var(--violet-50);color:var(--violet-700);padding:2px 7px;border-radius:4px}
.mail-composer .cbodyarea{flex:1;overflow-y:auto;padding:12px 14px;display:flex;flex-direction:column}
.mail-composer textarea{width:100%;flex:1;min-height:120px;border:none;outline:none;resize:none;font:400 13.5px/1.6 var(--font-sans);color:var(--fg-1);background:transparent}
.mail-composer .sig{font:400 12px/1.5 var(--font-sans);color:var(--fg-3);margin-top:12px;padding-top:10px;border-top:1px dashed var(--border-subtle);white-space:pre-line}
.mail-composer .atts{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.mail-composer .att{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 8px;border-radius:999px;background:var(--sky-50);color:var(--sky-700);font:500 11px/1 var(--font-sans)}
.mail-composer .att .x{cursor:pointer;opacity:.7;border:none;background:none;color:inherit;font-size:12px}
.mail-composer .err{color:var(--red-700);font-size:11.5px;padding:4px 14px}
.mail-composer .cfoot{display:flex;align-items:center;gap:8px;padding:10px 14px;border-top:1px solid var(--border);background:var(--bg-surface-2)}
.mail-composer .cfoot .btn{height:32px;padding:0 16px;border-radius:var(--r-md);font:600 12.5px/1 var(--font-sans);border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer}
.mail-composer .cfoot .btn[disabled]{opacity:.6;cursor:default}
.mail-composer .cfoot .lbl-file{width:32px;height:32px;border:1px solid var(--border);border-radius:var(--r-md);background:var(--bg-surface);display:inline-flex;align-items:center;justify-content:center;color:var(--fg-2);cursor:pointer;font-size:14px}
.mail-composer .cfoot .lbl-file input{display:none}
.mail-composer .cfoot .discard{border:none;background:none;color:var(--fg-3);cursor:pointer;font-size:12px}
.mail-composer .cfoot .spacer{flex:1}
.mail-composer .cfoot .save{font:400 11px/1 var(--font-sans);color:var(--fg-3);display:flex;align-items:center;gap:5px}
.mail-composer .cfoot .save .dot{width:5px;height:5px;border-radius:999px;background:var(--emerald-600)}
</style>

    <div class="chd">
        <span class="ttl">{{ $mode === 'compose' ? 'Новое письмо' : ($subject ?: 'Ответ') }}</span>
        <button class="wbtn" wire:click="close" title="Свернуть">–</button>
        <button class="wbtn" wire:click="discard" title="Удалить черновик">×</button>
    </div>

    <div class="cfields">
        <div class="crow">
            <span class="k">От</span>
            <span class="from"><span class="dot"></span>{{ $this->fromMailboxLabel }}</span>
            @if($relatedRequestId)
                <span class="reqbadge" title="Ответ отразится в заявке">заявка · пайплайн</span>
            @endif
        </div>
        <div class="crow">
            <span class="k">Кому</span>
            <input type="text" wire:model.live.debounce.1200ms="toRaw" placeholder="email, email …">
        </div>
        <div class="crow">
            <span class="k">Копия</span>
            <input type="text" wire:model.live.debounce.1200ms="ccRaw" placeholder="—">
        </div>
        <div class="crow">
            <span class="k">Тема</span>
            <input type="text" wire:model.live.debounce.1200ms="subject" placeholder="Тема письма">
        </div>
    </div>

    @error('subject')<div class="err">{{ $message }}</div>@enderror
    @error('toRaw')<div class="err">{{ $message }}</div>@enderror
    @error('bodyText')<div class="err">{{ $message }}</div>@enderror
    @error('newFiles.*')<div class="err">{{ $message }}</div>@enderror

    <div class="cbodyarea">
        <textarea wire:model.live.debounce.1200ms="bodyText" placeholder="Ваш ответ…"></textarea>

        @if($this->attachments->isNotEmpty())
            <div class="atts">
                @foreach($this->attachments as $att)
                    <span class="att" wire:key="ca-{{ $att->id }}">
                        {{ \Illuminate\Support\Str::limit($att->filename, 24) }}
                        <button class="x" wire:click="removeAttachment({{ $att->id }})" title="Убрать">×</button>
                    </span>
                @endforeach
            </div>
        @endif

        @if($this->signaturePreview)
            <div class="sig">{{ $this->signaturePreview }}</div>
        @endif
    </div>

    <div class="cfoot">
        <button class="btn" wire:click="send" wire:loading.attr="disabled" wire:target="send">
            <span wire:loading.remove wire:target="send">Отправить</span>
            <span wire:loading wire:target="send">Отправка…</span>
        </button>
        <label class="lbl-file" title="Прикрепить файл">
            📎<input type="file" multiple wire:model="newFiles">
        </label>
        <button class="discard" wire:click="discard">Удалить</button>
        <span class="spacer"></span>
        <span class="save" wire:loading.flex wire:target="updatedBodyText,updatedSubject,updatedToRaw,updatedCcRaw,uploadAttachments"><span class="dot"></span>Сохранение…</span>
    </div>
</div>
@endif
</div>
