{{-- Дублирование переписки QuoteParts — настройка сотрудника (раздел QuoteParts → «Пилот»); здесь только напоминание. --}}
@if(auth()->user()?->quoteparts_mirror)
    <div class="text-[12px] text-fg-3">
        ✉ Переписка дублируется вам на {{ app(\App\Services\QuoteParts\QuotePartsRfqService::class)->mirrorAddress(auth()->user()) }}:
        ответы поставщиков придут копией, ответ на копию уйдёт поставщику от MyZip. Пишите на языке поставщика.
    </div>
@endif
