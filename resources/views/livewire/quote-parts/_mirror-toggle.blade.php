{{-- Режим дублирования переписки QuoteParts на почту автора. Нужен $model — имя свойства компонента. --}}
<label class="flex items-start gap-2 text-[12.5px] cursor-pointer">
    <input type="checkbox" wire:model="{{ $model }}" class="mt-0.5 w-4 h-4 accent-sky-600">
    <span>
        <span class="font-medium text-fg-1">Дублировать переписку мне на почту</span>
        <span class="text-fg-3">— ответы поставщиков придут копией на {{ app(\App\Services\QuoteParts\QuotePartsRfqService::class)->mirrorAddress(auth()->user()) }}; ответьте на копию, и ответ уйдёт поставщику от MyZip. Пишите на языке поставщика.</span>
    </span>
</label>
