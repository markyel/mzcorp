{{-- Переписка с клиентом: вкладка «Переписка» и главная вкладка карточки (с 29.10.2026 переписка
     на главной целиком, а не одно первое письмо). Переменные — из detail.blade. --}}
@if($req->messenger_chat_id)
    {{-- Заявка из бота: переписка с клиентом в мессенджере (этап 2). --}}
    <livewire:requests.messenger-thread :request-id="$req->id" wire:key="messenger-thread-{{ $req->id }}" />
@endif
<div class="ds-card" x-data="{ selMode: false, ids: [] }">
    <div class="ds-card-header">
        <h3>Переписка</h3>
        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['thread']['count'] }}</span>
        <span class="flex-1"></span>
        @if(! $thread->isEmpty())
            {{-- Экспорт переписки в PDF. Без выбора — весь тред; режим
                 «Выбрать письма» даёт чекбоксы у каждого письма. Картинки
                 встраиваются в PDF, прочие вложения уходят в ZIP. --}}
            <a href="{{ route('requests.correspondence.export', $req) }}"
               x-show="!selMode"
               class="btn btn-sm mr-2"
               title="Скачать всю переписку в PDF (фото встроены; если есть другие вложения — ZIP с PDF и файлами)">⭳ Экспорт в PDF</a>
            <button type="button" x-show="!selMode" @click="selMode = true" class="btn btn-sm mr-2">Выбрать письма…</button>
            <template x-if="selMode">
                <span class="inline-flex items-center gap-2 mr-2">
                    <a :href="ids.length ? ('{{ route('requests.correspondence.export', $req) }}?messages=' + ids.join(',')) : '#'"
                       :class="ids.length ? '' : 'opacity-40 pointer-events-none'"
                       class="btn btn-sm btn-primary">⭳ Скачать выбранные (<span x-text="ids.length"></span>)</a>
                    <button type="button" @click="selMode = false; ids = []" class="btn btn-sm">Отмена</button>
                </span>
            </template>
            {{-- Порядок писем (персональная настройка, сохраняется per-user). --}}
            <button type="button" wire:click="toggleThreadSort" class="btn btn-sm mr-2"
                    title="Порядок писем в переписке — переключить (сохраняется в ваших настройках, применяется ко всем заявкам)">
                {{ $threadSort === 'desc' ? 'Сначала новые ↓' : 'Сначала старые ↑' }}
            </button>
        @endif
        @if($email?->mailbox)
            <span class="text-[11.5px] text-fg-3">канал: {{ $email->mailbox->email }} ↔ {{ $req->client_email }}</span>
        @endif
    </div>

    @php $orderedThread = $threadSort === 'desc' ? $thread->reverse() : $thread; @endphp
    @if($orderedThread->isEmpty())
        <div class="ds-card-body text-sm text-fg-3">Заявка создана не из e-mail.</div>
    @else
        <div>
            @foreach($orderedThread as $msg)
                @php
                    $isOutbound = $msg->direction === MailDirection::Outbound;
                    $authorName = $msg->from_name ?: $msg->from_email;
                    $authorInitial = \Illuminate\Support\Str::of($authorName)->substr(0, 1)->upper();
                    $catLabel = $msg->category
                        ? (\App\Enums\EmailCategory::tryFrom($msg->category)?->label() ?? $msg->category)
                        : null;
                @endphp
                <div class="border-b border-border-subtle last:border-b-0" wire:key="thread-msg-{{ $msg->id }}">
                    <div class="flex items-center gap-2.5 px-[18px] py-3">
                        <template x-if="selMode">
                            <input type="checkbox" value="{{ $msg->id }}" x-model.number="ids"
                                   class="shrink-0 w-4 h-4 accent-accent cursor-pointer"
                                   title="Включить это письмо в экспорт">
                        </template>
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-[11px] font-semibold shrink-0
                                     {{ $isOutbound ? 'bg-accent text-fg-on-accent' : 'bg-neutral-200 text-fg-2' }}">
                            {{ $authorInitial }}
                        </span>
                        <div class="min-w-0">
                            <div class="font-medium text-[13px] text-fg-1 truncate">
                                {{ $authorName }}
                            </div>
                            <div class="text-[11.5px] text-fg-3 mono truncate">
                                {{ $msg->from_email }} · {{ $msg->sent_at?->format('d.m.Y в H:i') ?? '—' }}
                                @if($msg->mailbox)
                                    · через {{ $msg->mailbox->email }}
                                @endif
                            </div>
                            {{-- Кому ушло письмо: без этого исходящее поставщику или
                                 письмо с копиями не отличить от ответа клиенту. --}}
                            @php
                                $rcpt = fn ($list) => collect($list ?? [])->filter(fn ($r) => is_array($r) && ! empty($r['email']))
                                    ->map(fn ($r) => trim((string) ($r['name'] ?? '')) !== '' ? $r['name'].' <'.$r['email'].'>' : $r['email']);
                                $toList = $rcpt($msg->to_recipients);
                                $ccList = $rcpt($msg->cc_recipients);
                                $short = fn ($c) => $c->take(3)->map(fn ($s) => preg_replace('/^.*<(.+)>$/', '$1', $s))->implode(', ')
                                    .($c->count() > 3 ? ' и ещё '.($c->count() - 3) : '');
                            @endphp
                            @if($toList->isNotEmpty() || $ccList->isNotEmpty())
                                <div class="text-[11.5px] text-fg-3 mono truncate"
                                     title="{{ $toList->isNotEmpty() ? 'Кому: '.$toList->implode(', ') : '' }}{{ $ccList->isNotEmpty() ? ($toList->isNotEmpty() ? "\n" : '').'Копия: '.$ccList->implode(', ') : '' }}">
                                    @if($toList->isNotEmpty())кому: {{ $short($toList) }}@endif
                                    @if($ccList->isNotEmpty()){{ $toList->isNotEmpty() ? ' · ' : '' }}копия: {{ $short($ccList) }}@endif
                                </div>
                            @endif
                        </div>
                        <span class="flex-1"></span>
                        {{-- Провенанс: сколько активных позиций заявки спаршено
                             из этого письма. Подсказка для разъединения заявки. --}}
                        @php $srcCount = $items->where('source_email_message_id', $msg->id)->count(); @endphp
                        @if($srcCount > 0)
                            <span class="chip chip-attn" title="Из этого письма спаршено позиций: {{ $srcCount }}"><span class="dot"></span>источник {{ $srcCount }} поз.</span>
                        @endif
                        {{-- По заявке отработали не всё: заводим наследника по этому
                             письму. Родитель не меняется — работает и на закрытых. --}}
                        @if($canSplit && ! $isOutbound)
                            <button type="button" class="btn btn-xs"
                                    wire:click="createSuccessorFromEmail({{ $msg->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="createSuccessorFromEmail({{ $msg->id }})"
                                    wire:confirm="Создать новую заявку по этому письму? Позиции скопируются, текущая заявка останется как есть."
                                    title="Создать заявку-наследника по этому письму: позиции копируются, родитель не меняется">
                                ⎘ заявка по письму
                            </button>
                        @endif
                        {{-- Письмо-основание наследника живёт в треде родителя:
                             показываем его здесь и честно говорим, чьё оно. --}}
                        @if((int) $msg->related_request_id !== (int) $req->id)
                            <span class="chip chip-warn" title="Письмо лежит в переписке родительской заявки — эта заявка заведена по нему">
                                <span class="dot"></span>письмо родителя
                            </span>
                        @endif
                        @if($isOutbound)
                            <span class="chip chip-info"><span class="dot"></span>исходящее</span>
                        @elseif($catLabel)
                            <span class="chip chip-ok"><span class="dot"></span>{{ $catLabel }}</span>
                        @endif
                    </div>
                    {{-- Тема письма: клиенты пишут в ней важное («M27099 - 5шт»,
                         M-2026-17605 — в тексте количества нет, парсер взял его из темы). --}}
                    <div class="px-[18px] pb-1.5 pl-[56px] -mt-1 text-[12.5px] text-fg-2 truncate"
                         title="{{ $msg->subject }}">
                        <span class="text-fg-3">Тема:</span>
                        @if(trim((string) $msg->subject) !== '')
                            <span class="font-medium text-fg-1">{{ $msg->subject }}</span>
                        @else
                            <span class="text-fg-4">(без темы)</span>
                        @endif
                    </div>
                    <div class="px-[18px] pb-3.5 pl-[56px] text-[13px] leading-[1.55] text-fg-1">
                        @include('livewire.requests.partials.email-body', ['msg' => $msg, 'html' => $this->bodyHtmlFor($msg), 'splitQuote' => $splitQuote])

                        @php
                            // Inline-кнопки «↩ Ответить / ↩↩ Ответить всем»
                            // под каждым сообщением треда. Используем $canReply
                            // (= owner / acting / admin / РОП / директорат)
                            // вместо старого hardcoded == assigned_user_id.
                            $canReplyHere = $canReply;
                            $isDraftMsg = (bool) $msg->is_draft;
                            $isMyDraft = $isDraftMsg && $msg->draft_author_user_id === auth()->id();
                        @endphp
                        @if($isDraftMsg)
                            @php
                                $canDiscardDraft = $isMyDraft || auth()->user()?->hasAnyRole(['admin', 'head_of_sales', 'director']);
                                // Не ушло из-за недоступного SMTP (OutgoingSendRetryService).
                                $sendStatus = $msg->send_status;
                            @endphp
                            @if($sendStatus)
                                <div class="mt-2 ds-card p-2 text-[11.5px] flex flex-wrap items-center gap-x-3 gap-y-1"
                                     style="{{ $sendStatus === \App\Enums\DraftSendStatus::Failed ? 'background:var(--red-50);border-color:var(--red-300)' : 'background:var(--amber-50);border-color:var(--amber-600)' }}">
                                    <span class="chip {{ $sendStatus === \App\Enums\DraftSendStatus::Failed ? 'chip-danger' : 'chip-attn' }}">{{ $sendStatus->icon() }} {{ $sendStatus->label() }}</span>
                                    <span class="text-fg-2">
                                        @if($sendStatus === \App\Enums\DraftSendStatus::Queued)
                                            Почтовый сервер не ответил, отправим автоматически
                                            @if($msg->send_next_attempt_at)
                                                в {{ $msg->send_next_attempt_at->format('H:i') }}
                                            @endif
                                            · попытка {{ (int) $msg->send_attempts }} из {{ (int) config('services.mail_outbound.retry_max_attempts') }}
                                            @if(app(\App\Services\Mail\OutgoingSendRetryService::class)->shouldUseFallback($msg))
                                                · следующая — через {{ app(\App\Services\Mail\MailFallbackSettings::class)->name() }}
                                            @endif
                                        @else
                                            Письмо не ушло клиенту, попыток: {{ (int) $msg->send_attempts }}
                                        @endif
                                    </span>
                                    @if($msg->send_last_error)
                                        <span class="text-fg-4 truncate max-w-full" title="{{ $msg->send_last_error }}">{{ \Illuminate\Support\Str::limit($msg->send_last_error, 90) }}</span>
                                    @endif
                                    <span class="flex gap-2 ml-auto">
                                        @if($isMyDraft)
                                            <button type="button" wire:click="sendThreadDraftNow({{ $msg->id }})" class="btn btn-sm">
                                                {{ $sendStatus === \App\Enums\DraftSendStatus::Failed ? 'Отправить ещё раз' : 'Отправить сейчас' }}
                                            </button>
                                        @endif
                                        @if($sendStatus === \App\Enums\DraftSendStatus::Queued && $canDiscardDraft)
                                            <button type="button" wire:click="cancelThreadDraftSend({{ $msg->id }})" class="btn btn-sm">Не отправлять</button>
                                        @endif
                                    </span>
                                </div>
                            @endif
                            <div class="mt-2 flex items-center gap-2 text-[11.5px] text-amber-700">
                                <span class="chip chip-attn"><span class="dot"></span>Черновик</span>
                                @if($isMyDraft)
                                    <button type="button"
                                            wire:click="$dispatch('open-draft', { draftId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                            class="underline">Продолжить редактирование</button>
                                @endif
                                @if($canDiscardDraft)
                                    <button type="button"
                                            wire:click="discardThreadDraft({{ $msg->id }})"
                                            wire:confirm="Удалить черновик? Текст и вложения черновика будут потеряны."
                                            class="underline text-red-700 hover:text-red-900">Удалить черновик</button>
                                @endif
                            </div>
                        @elseif($isOutbound && $msg->sent_via)
                            {{-- SMTP Яндекса не отвечал — ушло резервным каналом (OutgoingMailSender). --}}
                            <div class="mt-2 text-[11.5px] text-fg-3">↪ Отправлено через {{ $msg->sent_via }}: почтовый сервер Яндекса не отвечал</div>
                        @elseif($canReplyHere && ! $isOutbound)
                            <div class="mt-2 flex gap-2">
                                <button type="button"
                                        wire:click="$dispatch('open-reply', { messageId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                        class="btn btn-sm">↩ Ответить</button>
                                <button type="button"
                                        wire:click="$dispatch('open-reply-all', { messageId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                        class="btn btn-sm">↩↩ Ответить всем</button>
                            </div>
                        @endif

                        @include('livewire.requests.partials.email-attachments', ['msg' => $msg, 'isImageAttachment' => $isImageAttachment, 'isPdfAttachment' => $isPdfAttachment])
                    </div>
                </div>
            @endforeach

            {{-- Phase 1.9 — Compose / Reply form.
                 $canReply = owner / acting / admin / РОП / директорат
                 (см. блок «Actions» в Aside). Письмо уходит с
                 ящика assigned-менеджера через OutgoingMailboxResolver,
                 даже если отправляет админ. --}}
            @if($canReply)
                <div class="px-[18px] py-3.5 bg-surface-2 border-t border-border">
                    <div class="flex items-center gap-2">
                        <span class="text-[12px] text-fg-3">
                            Ответ клиенту через MyLift — копия сохранится в Sent ящика
                            @if(! $isOwner && ! $isDelegate && $req->assignedUser)
                                <span class="text-fg-2">{{ $req->assignedUser->name }}</span>.
                                <span class="text-amber-700">(вы отправляете от его имени)</span>
                            @else
                                ящика.
                            @endif
                            Письмо пишется в плавающем окне — можно параллельно
                            переключаться на другие вкладки.
                        </span>
                        <span class="flex-1"></span>
                        @if($req->client_email)
                            <button type="button"
                                    wire:click="$dispatch('open-compose', { requestId: {{ $req->id }} })"
                                    class="btn btn-sm">＋ Новое сообщение клиенту</button>
                        @endif
                    </div>
                    {{-- Сам композер — плавающее окно, зарегистрирован ГЛОБАЛЬНО
                         внизу шаблона (вне @switch табов), чтобы жить при
                         переключении вкладок. --}}
                </div>
            @else
                <div class="px-[18px] py-3.5 bg-surface-2 border-t border-border text-[12px] text-fg-3">
                    Отвечать на эту заявку может только назначенный менеджер
                    ({{ $req->assignedUser?->name ?? '— не назначен —' }}),
                    acting (делегат) или admin/РОП/директорат.
                </div>
            @endif
        </div>
    @endif
</div>
