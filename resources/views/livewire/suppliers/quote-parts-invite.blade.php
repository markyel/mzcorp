<div class="ds-card">
    @php
        $inputCls = 'h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500';
        $status = $supplier->quoteparts_status;
    @endphp
    <div class="ds-card-header">
        <h3>QuoteParts</h3>
        @if($status)
            <span class="chip {{ $status === 'active' ? 'chip-ok' : ($status === 'terms_discussed' ? 'chip-warn' : ($status === 'declined' ? 'chip-danger' : 'chip-sky')) }} text-[10.5px] ml-2">{{ $labels[$status] ?? $status }}</span>
            <span class="text-[12px] text-fg-3 ml-2">приглашён {{ $supplier->quoteparts_invited_at?->format('d.m.Y') }}</span>
            <button type="button" wire:click="refreshStatus" class="btn btn-xs ml-auto">Обновить статус</button>
        @else
            <span class="text-[12px] text-fg-3 ml-2">платформа работы с поставщиками · тестирование</span>
        @endif
    </div>
    <div class="ds-card-body space-y-3 text-[13px]">
        @if($supplier->quoteparts_comment)
            <div class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-amber-900">
                Поставщик предлагает: «{{ $supplier->quoteparts_comment }}». Измените условия и пригласите ещё раз.
            </div>
        @endif

        @unless($configured)
            <div class="text-amber-800">Ключ API QuoteParts не задан — приглашение не отправится.</div>
        @endunless

        @if(! $open)
            <button type="button" wire:click="$set('open', true)" class="btn btn-sm {{ $status ? '' : 'btn-primary' }}">
                {{ $status ? 'Изменить условия и пригласить ещё раз' : 'Пригласить в QuoteParts' }}
            </button>
            @if(! $status)
                <span class="text-fg-4 text-[12px] ml-2">Поставщик получит письмо от MyZip на английском и китайском и примет режим цены по ссылке.</span>
            @endif
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <label class="block"><span class="text-fg-3 text-[12px]">Компания (как увидит поставщик)</span>
                    <input type="text" wire:model="company_name" class="{{ $inputCls }}">
                    @error('company_name')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="text-fg-3 text-[12px]">Страна (ISO, напр. CN)</span>
                    <input type="text" wire:model="country" maxlength="2" class="{{ $inputCls }} uppercase">
                    @error('country')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="text-fg-3 text-[12px]">Контактное лицо</span>
                    <input type="text" wire:model="contact_name" class="{{ $inputCls }}"></label>
                <label class="block"><span class="text-fg-3 text-[12px]">Почта</span>
                    <input type="email" wire:model="email" class="{{ $inputCls }}">
                    @error('email')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="text-fg-3 text-[12px]">Язык страниц поставщика</span>
                    <select wire:model="locale" class="{{ $inputCls }}"><option value="en">English</option><option value="zh_CN">中文</option></select>
                    <span class="text-fg-4 text-[11px]">Письмо-приглашение всегда на двух языках.</span></label>
            </div>

            <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold pt-1">Режим цены — в нём поставщик будет давать все офферы</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <label class="block"><span class="text-fg-3 text-[12px]">Incoterms</span>
                    <select wire:model="incoterm" class="{{ $inputCls }}">
                        <option value="">— не задано —</option>
                        @foreach(\App\Livewire\Suppliers\QuotePartsInvite::INCOTERMS as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                    </select></label>
                <label class="block"><span class="text-fg-3 text-[12px]">Место (порт, склад)</span>
                    <input type="text" wire:model="incoterm_place" placeholder="Ningbo / Factory" class="{{ $inputCls }}">
                    @error('incoterm_place')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="text-fg-3 text-[12px]">Валюта</span>
                    <select wire:model="currency" class="{{ $inputCls }}">
                        <option value="">—</option>
                        @foreach(\App\Livewire\Suppliers\QuotePartsInvite::CURRENCIES as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select></label>
                <label class="block md:col-span-2"><span class="text-fg-3 text-[12px]">Оплата</span>
                    <input type="text" wire:model="payment_terms" list="qp-payment" placeholder="100% prepayment" class="{{ $inputCls }}">
                    <datalist id="qp-payment">
                        <option value="100% prepayment"><option value="30% deposit, 70% before shipment"><option value="50% deposit, 50% before shipment"><option value="Payment after shipment, 30 days">
                    </datalist></label>
                <label class="block"><span class="text-fg-3 text-[12px]">Упаковка</span>
                    <select wire:model="packaging" class="{{ $inputCls }}"><option value="">не указано</option><option value="1">включена в цену</option><option value="0">не включена</option></select></label>
                <label class="block"><span class="text-fg-3 text-[12px]">Срок действия офферов, дней</span>
                    <input type="number" wire:model="offer_validity_days" min="1" max="730" class="{{ $inputCls }}">
                    @error('offer_validity_days')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror</label>
            </div>

            <label class="block"><span class="text-fg-3 text-[12px]">Сообщение поставщику (по-английски, необязательно)</span>
                <textarea wire:model="message" rows="3" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500" placeholder="Dear Lily, we'd like to move our price requests to QuoteParts…"></textarea></label>

            @if($error)<div class="text-red-700 text-[12.5px]">{{ $error }}</div>@endif

            <div class="flex gap-2">
                <button type="button" wire:click="invite" wire:loading.attr="disabled" class="btn btn-sm btn-primary">Отправить приглашение</button>
                <button type="button" wire:click="$set('open', false)" class="btn btn-sm">Отмена</button>
            </div>
        @endif
    </div>
</div>
