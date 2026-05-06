<div class="space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="font-mono text-xs px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                    {{ $request->internal_code }}
                </span>
                <span class="inline-block px-2 py-0.5 rounded text-xs
                    {{ $request->status->value === 'new' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                    {{ $request->status->label() }}
                </span>
                <span class="text-xs text-gray-500">
                    создана {{ $request->created_at?->format('d.m.Y H:i') }}
                </span>
            </div>
            <h1 class="mt-2 text-xl font-semibold text-gray-900 dark:text-gray-100">
                {{ $request->subject ?: '(без темы)' }}
            </h1>
        </div>
        <a href="{{ route('requests.index') }}"
           class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">← к пулу</a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2 space-y-4">

            <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="text-sm font-medium text-gray-500 mb-3">Исходное письмо</h3>
                @if($request->emailMessage)
                    <div class="text-sm space-y-1 mb-4">
                        <div><span class="text-gray-500">От:</span>
                            {{ $request->emailMessage->from_name ? $request->emailMessage->from_name . ' &lt;' . $request->emailMessage->from_email . '&gt;' : $request->emailMessage->from_email }}
                        </div>
                        <div><span class="text-gray-500">Ящик:</span>
                            {{ $request->emailMessage->mailbox?->email }}
                        </div>
                        <div><span class="text-gray-500">Дата:</span>
                            {{ $request->emailMessage->sent_at?->format('d.m.Y H:i') ?? '—' }}
                        </div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                        @if($request->emailMessage->body_html)
                            <div class="prose prose-sm dark:prose-invert max-w-none">
                                {!! $request->emailMessage->body_html !!}
                            </div>
                        @elseif($request->emailMessage->body_plain)
                            <pre class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300 font-sans">{{ $request->emailMessage->body_plain }}</pre>
                        @else
                            <div class="text-sm text-gray-500">(пустое тело)</div>
                        @endif
                    </div>

                    @if($request->emailMessage->attachments->isNotEmpty())
                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <h4 class="text-xs uppercase text-gray-500 mb-2">Вложения ({{ $request->emailMessage->attachments->count() }})</h4>
                            <ul class="space-y-1 text-sm">
                                @foreach($request->emailMessage->attachments as $att)
                                    <li class="flex items-center justify-between gap-3">
                                        <span class="truncate">{{ $att->filename }}</span>
                                        <span class="text-xs text-gray-500 whitespace-nowrap">
                                            {{ $att->mime_type ?: '—' }}
                                            @if($att->size_bytes)
                                                · {{ number_format($att->size_bytes / 1024, 0) }} KB
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @else
                    <div class="text-sm text-gray-500">Заявка создана не из email-а.</div>
                @endif
            </div>
        </div>

        <div class="space-y-4">

            <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="text-sm font-medium text-gray-500 mb-3">Клиент</h3>
                <div class="text-sm">
                    <div class="font-medium">{{ $request->client_name ?: $request->client_email }}</div>
                    @if($request->client_name)
                        <div class="text-gray-500 text-xs">{{ $request->client_email }}</div>
                    @endif
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="text-sm font-medium text-gray-500 mb-3">Менеджер</h3>
                <div class="text-sm">
                    {{ $request->assignedUser?->name ?? '— не назначен —' }}
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="text-sm font-medium text-gray-500 mb-3">История назначений</h3>
                @if($request->assignments->isEmpty())
                    <div class="text-sm text-gray-500">—</div>
                @else
                    <ul class="text-xs space-y-2">
                        @foreach($request->assignments as $a)
                            <li>
                                <div>{{ $a->user?->name ?? '—' }}</div>
                                <div class="text-gray-500">
                                    {{ $a->reason }} · {{ $a->assigned_at?->format('d.m.Y H:i') }}
                                    @if($a->assignedBy)
                                        · {{ $a->assignedBy->name }}
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
