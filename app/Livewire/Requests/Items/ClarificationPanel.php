<?php

namespace App\Livewire\Requests\Items;

use App\Models\ClarificationBatch;
use App\Models\ClarificationQuestion;
use App\Models\Request as RequestModel;
use App\Services\Mail\EmailDraftService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Foundation §6.2 — структурированные уточняющие вопросы клиенту.
 *
 * Менеджер видит панель внизу таба «Позиции»: за каждой позицией —
 * textarea «Вопрос клиенту», плюс «Общий вопрос». При нажатии
 * «📨 Сформировать письмо» создаются:
 *   1. ClarificationBatch (status=drafted)
 *   2. ClarificationQuestion rows (по непустым textarea)
 *   3. EmailMessage draft с предзаполненным body (генерируется из
 *      батча). В draft.detected_artifacts кладём marker
 *      `clarification_batch:<id>` чтобы ComposeForm::send знал что
 *      это clarification и применил post-send hook.
 *   4. Dispatch `clarification-letter-ready` → Detail переключает таб
 *      на «Переписка» и сам диспатчит open-draft → ComposeForm
 *      раскрывает форму с готовым письмом, оператор может править
 *      subject/body/attachments и нажать «Отправить».
 *
 * Visibility: только assigned manager + acting (delegation) +
 * privileged head_of_sales/director могут составлять. У secretary
 * вкладка-readonly, панель не показывается.
 */
class ClarificationPanel extends Component
{
    public int $requestId;

    /**
     * Map item_id → текст вопроса. Сериализуется в Livewire-сессии.
     * @var array<int, string>
     */
    public array $perItem = [];

    /** Общий вопрос (не привязан к конкретной позиции). */
    public string $generalQuestion = '';

    public bool $expanded = false;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
    }

    /**
     * Foundation §6.2 + комбо-режим: карточка (slot / quick-chip /
     * free-text textarea) дёргает «+ спросить» — аппендим заполненный
     * template в `perItem[itemId]`. Если поле уже не пустое — пишем с
     * новой строки.
     *
     * NB: панель НЕ раскрываем автоматически — thin info-bar внизу сам
     * обновит счётчик. Раскрытие большого preview — только по явному
     * клику на «👁 Предпросмотр».
     *
     * Payload: {itemId, slotKey, slotLabel, template, itemName?}
     */
    #[On('clarification-add-slot-question')]
    public function addSlotQuestion(
        int $itemId,
        ?string $slotKey = null,
        ?string $slotLabel = null,
        ?string $template = null,
        ?string $itemName = null,
    ): void {
        $tpl = trim((string) $template);
        if ($tpl === '') {
            // Fallback по label: «Уточните <label>».
            $tpl = $slotLabel
                ? sprintf('Уточните, пожалуйста: %s.', mb_strtolower($slotLabel))
                : 'Уточните, пожалуйста, эту позицию.';
        }
        // Простая подстановка плейсхолдеров если template из KB.
        if ($itemName) {
            $tpl = str_replace(['{name}', '{item}'], $itemName, $tpl);
        }

        $current = trim((string) ($this->perItem[$itemId] ?? ''));
        $this->perItem[$itemId] = $current === ''
            ? $tpl
            : $current . "\n" . $tpl;
    }

    /**
     * Сколько непустых вопросов сейчас в форме.
     */
    #[Computed]
    public function pendingCount(): int
    {
        $count = 0;
        if (trim($this->generalQuestion) !== '') {
            $count++;
        }
        foreach ($this->perItem as $q) {
            if (trim((string) $q) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Сформировать batch + draft и открыть ComposeForm.
     *
     * @return mixed Livewire-friendly return.
     */
    public function formLetter(EmailDraftService $drafts)
    {
        $request = RequestModel::with('items')->findOrFail($this->requestId);

        // Permission: то же что Detail::canManage (owner ИЛИ delegate ИЛИ priv).
        $user = auth()->user();
        if (! $user || ! $request->isAccessibleBy($user)) {
            $this->addError('generalQuestion', 'Только assigned-менеджер или РОП.');

            return null;
        }
        if ($user->hasRole('secretary')) {
            $this->addError('generalQuestion', 'Секретарь только просматривает.');

            return null;
        }

        // Собираем непустые вопросы.
        $itemQuestions = [];
        foreach ($this->perItem as $itemId => $q) {
            $q = trim((string) $q);
            if ($q !== '') {
                $itemQuestions[(int) $itemId] = $q;
            }
        }
        $generalQ = trim($this->generalQuestion);

        if ($generalQ === '' && empty($itemQuestions)) {
            $this->addError('generalQuestion', 'Введите хотя бы один вопрос.');

            return null;
        }

        $items = $request->items->keyBy('id');

        $batch = DB::transaction(function () use ($request, $itemQuestions, $generalQ, $items, $user) {
            /** @var ClarificationBatch $batch */
            $batch = ClarificationBatch::create([
                'request_id' => $request->id,
                'created_by_user_id' => $user->id,
                'status' => ClarificationBatch::STATUS_DRAFTED,
                'general_question' => $generalQ !== '' ? $generalQ : null,
            ]);

            foreach ($itemQuestions as $itemId => $question) {
                // Защита — item должен принадлежать этой Request.
                if (! $items->has($itemId)) {
                    continue;
                }
                ClarificationQuestion::create([
                    'batch_id' => $batch->id,
                    'request_item_id' => $itemId,
                    'question' => $question,
                ]);
            }

            return $batch;
        });

        $bodyPlain = $this->renderBody($request, $batch->fresh('questions.requestItem'));

        // Phase 6.2: создаём reply на последнее inbound клиента — даёт
        // In-Reply-To/References (threading в клиентской почте) и
        // OutgoingMailMimeBuilder приклеит цитату оригинала. Если inbound
        // нет (заявка создана не из email или письмо отвалилось) —
        // fallback на createCompose без цитаты.
        $lastInbound = \App\Models\EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->where('direction', \App\Enums\MailDirection::Inbound->value)
            ->where('is_draft', false)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        $draft = $lastInbound
            ? $drafts->createReply($request, $lastInbound, $user, false)
            : $drafts->createCompose($request, $user);

        // Subject:
        //  - reply: createReply уже поставил «Re: <orig>». Оставляем как есть,
        //    клиент увидит «Re: ...» — Yandex/Outlook сгруппируют в один thread.
        //  - compose: ставим осмысленный subject с кодом заявки.
        $newSubject = $lastInbound
            ? $draft->subject // от createReply
            : '[' . $request->internal_code . '] Уточнения по заявке';

        $artifacts = is_array($draft->detected_artifacts ?? null) ? $draft->detected_artifacts : [];
        $artifacts[] = [
            'type' => 'clarification_batch',
            'batch_id' => $batch->id,
            'transition_to_status' => 'awaiting_client_clarification',
            'created_at' => now()->toIso8601String(),
        ];

        $draft->forceFill([
            'subject' => $newSubject,
            'body_plain' => $bodyPlain,
            'detected_artifacts' => $artifacts,
            'last_edited_at' => now(),
        ])->save();

        // Linkуем draft с batch.
        $batch->update(['draft_email_id' => $draft->id]);

        // Очищаем форму + закрываем панель.
        $this->perItem = [];
        $this->generalQuestion = '';
        $this->expanded = false;

        // Уведомляем parent Detail — он переключит таб на «Переписка»
        // (там зарегистрирован ComposeForm) и сам сделает dispatch open-draft.
        // Прямой open-draft из таба «Позиции» не работает: ComposeForm
        // не отрендерен, событие никто не ловит.
        $this->dispatch('clarification-letter-ready', draftId: $draft->id);

        session()->flash('status', sprintf(
            'Сформирован черновик письма с %d вопросом(ами). Проверьте, отредактируйте и отправьте.',
            $batch->questions()->count(),
        ));

        return null;
    }

    /**
     * Генерация body для clarification-письма.
     */
    private function renderBody(RequestModel $request, ClarificationBatch $batch): string
    {
        $clientGreeting = $request->client_name
            ? 'Здравствуйте, ' . $request->client_name . '!'
            : 'Здравствуйте!';

        $lines = [];
        $lines[] = $clientGreeting;
        $lines[] = '';
        $lines[] = sprintf(
            'По вашему запросу №%s%s нужны уточнения, чтобы корректно подобрать предложение.',
            $request->internal_code,
            $request->subject ? ' («' . trim($request->subject) . '»)' : '',
        );
        $lines[] = '';

        if ($batch->general_question !== null && $batch->general_question !== '') {
            $lines[] = $batch->general_question;
            $lines[] = '';
        }

        $perItemQuestions = $batch->questions->filter(fn ($q) => $q->request_item_id !== null);
        if ($perItemQuestions->isNotEmpty()) {
            $lines[] = 'По позициям:';
            foreach ($perItemQuestions as $q) {
                $item = $q->requestItem;
                if (! $item) {
                    continue;
                }
                $label = sprintf(
                    '%d. %s',
                    $item->position,
                    trim((string) $item->parsed_name) ?: '(без названия)',
                );
                if ($item->parsed_brand) {
                    $label .= ' (' . $item->parsed_brand . ')';
                }
                if ($item->parsed_article) {
                    $label .= ' — арт. ' . $item->parsed_article;
                }
                $lines[] = $label;
                $lines[] = '   ' . $q->question;
                $lines[] = '';
            }
        }

        $lines[] = 'Жду вашего ответа. Спасибо!';

        return implode("\n", $lines);
    }

    public function render()
    {
        $request = RequestModel::with([
            'items' => fn ($q) => $q->where('is_active', true)->orderBy('position'),
        ])->find($this->requestId);

        $items = $request?->items ?? collect();

        // Computed: разрешён ли пользователь.
        $user = auth()->user();
        $canCompose = $request && $user
            && $request->isAccessibleBy($user)
            && ! $user->hasRole('secretary');

        return view('livewire.requests.items.clarification-panel', [
            'request' => $request,
            'items' => $items,
            'canCompose' => $canCompose,
        ]);
    }
}
