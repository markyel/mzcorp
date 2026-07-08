<x-mail::message>
# {{ $toAuthor ? ($messages->count() > 1 ? 'Новые ответы' : 'Новый ответ') : ($messages->count() > 1 ? 'Новые сообщения' : 'Новое сообщение') }} по обращению #{{ $ticket->id }}

**{{ $ticket->subject }}**

@if($toAuthor && ($questionBody ?? null) !== null)
**Ваш вопрос** ({{ $ticket->created_at?->format('d.m.Y H:i') }}):

> {{ str_replace("\n", "\n> ", $questionBody) }}
@endif

@foreach($messages as $message)
---

**{{ $message->author?->name }}** · {{ $message->created_at?->format('d.m.Y H:i') }}:

{{ $message->body }}

@if($message->attachments->isNotEmpty())
Вложения:
@foreach($message->attachments as $att)
- {{ $att->original_name }} ({{ $att->humanSize() }})
@endforeach
@endif
@endforeach

<x-mail::button :url="$url">
Открыть обращение
</x-mail::button>

— MyLift CRM
</x-mail::message>
