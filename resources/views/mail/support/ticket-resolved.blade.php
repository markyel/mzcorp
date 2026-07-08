<x-mail::message>
# Обращение #{{ $ticket->id }} решено ✅

**{{ $ticket->subject }}**

@if(($questionBody ?? null) !== null)
**Ваш вопрос** ({{ $ticket->created_at?->format('d.m.Y H:i') }}):

> {{ str_replace("\n", "\n> ", $questionBody) }}
@endif

@foreach($answers as $answer)
---

**Ответ** ({{ $answer->author?->name }} · {{ $answer->created_at?->format('d.m.Y H:i') }}):

{{ $answer->body }}
@endforeach

<x-mail::button :url="$url">
Открыть обращение
</x-mail::button>

Если вопрос не решён — просто ответьте в обращении, оно откроется снова.

— MyLift CRM
</x-mail::message>
