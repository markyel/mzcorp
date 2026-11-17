{{-- Опрос «Фото для календаря»: вход не удался или ссылка отправлена. $reason: unknown | used | expired | closed | sent | anonymous;
     $voter — участник по ссылке (если нашёлся), $token — старая ссылка (для «прислать новую»), $poll — опрос (если известен). --}}
@extends('layouts.poll')
@section('title', ($poll ?? $voter?->poll)?->title ?? 'Фото для календаря')
@section('content')
    @php
        $masked = $voter ? \App\Services\CalendarPoll\VoterAccess::maskEmail($voter->email) : null;
        $pollRef = $poll ?? $voter?->poll;
    @endphp
    <div style="max-width: 520px; margin: 40px auto; padding: 20px 22px; background: var(--bg-surface, #fff); border: 1px solid var(--border-subtle); border-radius: 10px; font-size: 14px; line-height: 1.55;">
        @switch($reason)
            @case('used')
                <p style="margin-top: 0"><b>Эта ссылка уже открывалась на другом устройстве.</b></p>
                <p>Ссылка личная и одноразовая. Пришлём новую на {{ $masked }} — откройте её там, где будете выбирать.</p>
                <form method="POST" action="{{ route('calendar-poll.resend', ['token' => $token]) }}">@csrf
                    <button type="submit" class="btn btn-primary">Прислать новую ссылку</button>
                </form>
                @break
            @case('expired')
                <p style="margin-top: 0"><b>Срок ссылки вышел.</b></p>
                <p>Пришлём новую на {{ $masked }}.</p>
                <form method="POST" action="{{ route('calendar-poll.resend', ['token' => $token]) }}">@csrf
                    <button type="submit" class="btn btn-primary">Прислать новую ссылку</button>
                </form>
                @break
            @case('closed')
                <p style="margin: 0">Голосование ещё не открыто. Ссылка заработает, когда организатор его откроет.</p>
                @break
            @case('sent')
                <p style="margin-top: 0"><b>Ссылка отправлена.</b></p>
                <p style="margin-bottom: 0">Если адрес есть в списке участников, письмо с новой ссылкой придёт в течение минуты — проверьте и «Спам». Повторно — не раньше чем через 5 минут.</p>
                @break
            @case('anonymous')
                <p style="margin-top: 0"><b>Войдите по личной ссылке из письма.</b></p>
                <p>Нет письма или открываете на другом устройстве? Укажите рабочий адрес — если он есть в списке участников, пришлём ссылку.</p>
                <form method="POST" action="{{ route('calendar-poll.request-link', $pollRef) }}" style="display: flex; gap: 8px; flex-wrap: wrap;">@csrf
                    <input type="email" name="email" required placeholder="you@myzip.ru" style="flex: 1; min-width: 200px; padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px;">
                    <button type="submit" class="btn btn-primary">Прислать ссылку</button>
                </form>
                @break
            @default
                <p style="margin: 0">Ссылка не найдена — возможно, вам уже прислали новую. Откройте последнее письмо с приглашением.</p>
        @endswitch
    </div>
@endsection
