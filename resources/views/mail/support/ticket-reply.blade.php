<x-mail::message>
# Ответ по тикету #{{ $ticket->id }}

**{{ $ticket->subject }}**
Ответил: **{{ $author->name }}** · {{ $message->created_at?->format('d.m.Y H:i') }}

---

{{ $message->body }}

---

@if($message->attachments->isNotEmpty())
**Вложения к ответу:**
@foreach($message->attachments as $att)
- {{ $att->original_name }} ({{ $att->humanSize() }})
@endforeach
@endif

<x-mail::button :url="$url">
Открыть тикет
</x-mail::button>

— MyLift CRM
</x-mail::message>
