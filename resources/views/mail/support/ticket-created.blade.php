<x-mail::message>
# Новый тикет в поддержку MyLift

**Тикет:** #{{ $ticket->id }} — {{ $ticket->subject }}
**Автор:** {{ $author->name }} ({{ $author->email }})
**Роли:** {{ implode(', ', $ctx['roles_snapshot'] ?? []) ?: '—' }}
**Создан:** {{ $ticket->created_at?->format('d.m.Y H:i') }}

---

{{ $ticket->body }}

---

**Где был пользователь:**

- URL: {{ $ctx['url'] ?? '—' }}
- Route: `{{ $ctx['route_name'] ?? '—' }}`
- Viewport: {{ $ctx['viewport'] ?? '—' }}
- User-Agent: {{ \Illuminate\Support\Str::limit($ctx['user_agent'] ?? '—', 160) }}

@if($ticket->initialAttachments->isNotEmpty())
**Вложения:**
@foreach($ticket->initialAttachments as $att)
- {{ $att->original_name }} ({{ $att->humanSize() }})
@endforeach
@endif

<x-mail::button :url="$url">
Открыть тикет
</x-mail::button>

— MyLift CRM
</x-mail::message>
