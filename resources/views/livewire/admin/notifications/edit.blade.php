<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {{-- Левая колонка: форма редактирования --}}
    <div class="lg:col-span-2 space-y-4">
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
            <h3 class="font-semibold text-base text-gray-900 mb-1">{{ $template->type->label() }}</h3>
            <p class="text-sm text-gray-600 leading-relaxed">{{ $template->type->description() }}</p>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Тема письма</label>
                <input type="text"
                       wire:model.live.debounce.500ms="subjectTemplate"
                       class="w-full rounded border-gray-300 focus:border-red-500 focus:ring-red-500 text-sm">
                @error('subjectTemplate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Тело письма (Markdown)</label>
                <textarea wire:model.live.debounce.500ms="bodyTemplate"
                          rows="14"
                          class="w-full rounded border-gray-300 focus:border-red-500 focus:ring-red-500 text-sm font-mono">{{ $bodyTemplate }}</textarea>
                @error('bodyTemplate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">Поддерживается Markdown (**жирный**, [ссылки](url), списки). Все письма обёрнуты в общий HTML-шаблон MyZip с шапкой и подписью.</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Порог, часов <span class="text-gray-400">(оставьте пустым для дефолта)</span></label>
                    <input type="number"
                           wire:model.live="thresholdHours"
                           min="1"
                           class="w-full rounded border-gray-300 focus:border-red-500 focus:ring-red-500 text-sm">
                    <p class="mt-1 text-xs text-gray-500">Через сколько часов после события напомнить. По умолчанию берётся из «Attention · дедлайны».</p>
                </div>
                @if($template->type === \App\Enums\ClientNotificationType::InvoiceExpiringSoon)
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">За сколько дней до истечения</label>
                    <input type="number"
                           wire:model.live="warningDays"
                           min="1"
                           class="w-full rounded border-gray-300 focus:border-red-500 focus:ring-red-500 text-sm">
                </div>
                @endif
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="button"
                        wire:click="save"
                        class="inline-flex items-center px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white text-sm rounded shadow-sm">
                    Сохранить шаблон
                </button>
                <button type="button"
                        wire:click="preview"
                        class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-sm rounded">
                    Превью
                </button>
            </div>
        </div>

        @if($previewError)
            <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded p-3">
                {{ $previewError }}
            </div>
        @endif

        @if($previewHtml)
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <h4 class="font-medium text-gray-900 mb-1">Превью письма</h4>
                <div class="text-xs text-gray-500 mb-2">Тема: <span class="font-medium text-gray-700">{{ $previewSubject }}</span></div>
                <iframe class="w-full rounded border border-gray-200" style="height:560px;" srcdoc="{{ $previewHtml }}"></iframe>
            </div>
        @endif
    </div>

    {{-- Правая колонка: плейсхолдеры + выбор тестовой заявки --}}
    <div class="space-y-4">
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
            <h4 class="font-medium text-gray-900 mb-2">Доступные плейсхолдеры</h4>
            <div class="space-y-1.5 text-xs">
                @foreach($placeholders as $key => $description)
                    <div class="font-mono">
                        <code class="text-violet-700 bg-violet-50 px-1.5 py-0.5 rounded border border-violet-100">{{ '{{ '.$key.' }}' }}</code>
                        <div class="text-gray-600 ml-2 mt-0.5">{{ $description }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
            <h4 class="font-medium text-gray-900 mb-2">Превью на конкретной заявке</h4>
            <select wire:model.live="previewRequestId"
                    class="w-full rounded border-gray-300 focus:border-red-500 focus:ring-red-500 text-sm">
                <option value="">— последняя заявка с client_email —</option>
                @foreach($sampleRequests as $r)
                    <option value="{{ $r->id }}">
                        {{ $r->internal_code }} · {{ \Illuminate\Support\Str::limit($r->client_email, 30) }}
                    </option>
                @endforeach
            </select>
            <p class="mt-2 text-xs text-gray-500">
                Превью использует реальные данные заявки: код, имя клиента, ответственный менеджер. Type-specific поля (сумма счёта, число вопросов и т.п.) заполняются примерами.
            </p>
        </div>
    </div>
</div>
