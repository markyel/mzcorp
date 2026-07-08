<x-mail::message>
# Ответ по тикету #{{ $ticket->id }}

**{{ $ticket->subject }}**
Ответил: **{{ $author->name }}** · {{ $message->created_at?->format('d.m.Y H:i') }}

@if(($question ?? null) !== null)
---

**Ваш вопрос** ({{ $question->created_at?->format('d.m.Y H:i') }}):

> {{ str_replace("\n", "\n> ", trim($question->body)) }}
@endif

---

**Ответ:**

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
