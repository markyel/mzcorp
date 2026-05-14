<?php

namespace App\Livewire\Admin\MailReview;

use App\Enums\EmailClassification;
use App\Enums\MailDirection;
use App\Enums\RequestStatus;
use App\Jobs\Mail\ParseRequestItemsJob;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Request\InternalCodeGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Foundation Фаза 2: auto-rejection нерелевантных + reopen РОПом.
 *
 * AI-классификатор отсортировал письма в категории, отличные от `request`
 * (`irrelevant`, `reclamation`, `accounting`, `general_question`, `spam`,
 * `other`). Для каждого такого письма Request НЕ создаётся — это и есть
 * «auto-rejection».
 *
 * Иногда AI ошибается: реальная заявка попадает в irrelevant из-за
 * шаблонного тела (Правило 3 в промпте обычно срабатывает, но не всегда).
 * Этот экран — рабочий интерфейс РОПа: пересмотреть AI-решения и
 * принудительно создать Request там, где AI ошибся.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Фильтр по AI-классификации. Default = irrelevant (самая частая ошибка
     * — false-negative на «запрос с шаблонным телом + вложение»).
     */
    #[Url(as: 'class')]
    public string $classification = 'irrelevant';

    /**
     * Окно по времени: today / 7d / 30d / 90d / all.
     */
    #[Url(as: 'window')]
    public string $window = '30d';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingClassification(): void
    {
        $this->resetPage();
    }

    public function updatingWindow(): void
    {
        $this->resetPage();
    }

    public function setClass(string $class): void
    {
        $this->classification = $class;
        $this->resetPage();
    }

    public function setWindow(string $window): void
    {
        $this->window = in_array($window, ['today', '7d', '30d', '90d', 'all'], true) ? $window : '30d';
        $this->resetPage();
    }

    /**
     * Реоткрыть письмо как Request. Создаём Request со статусом Pending,
     * dispatch'им парсер позиций — дальше pipeline идёт обычным путём
     * (RequestItemPersister → autoAssign → MailFolderRouter).
     *
     * Запись о ручном реоткрытии — в email_messages.detected_artifacts
     * (как и для DocumentDetector — единое поле под audit AI-overrides).
     */
    public function reopenAsRequest(int $emailId, InternalCodeGenerator $codeGen): void
    {
        $email = EmailMessage::query()
            ->where('direction', MailDirection::Inbound->value)
            ->whereKey($emailId)
            ->first();
        if (! $email) {
            return;
        }
        if ($email->related_request_id) {
            session()->flash('status', 'Это письмо уже связано с заявкой #' . $email->related_request_id);

            return;
        }

        $request = DB::transaction(function () use ($email, $codeGen) {
            $req = Request::create([
                'internal_code' => $codeGen->next(),
                'email_message_id' => $email->id,
                'status' => RequestStatus::Pending,
                'client_email' => $email->from_email ?: '',
                'client_name' => $email->from_name,
                'subject' => $email->subject,
            ]);
            $email->forceFill(['related_request_id' => $req->id])->save();

            // Audit: ручное переоткрытие AI-решения.
            $existing = is_array($email->detected_artifacts ?? null)
                ? $email->detected_artifacts
                : [];
            $existing[] = [
                'type' => 'manual_reopen_as_request',
                'overrode_ai_classification' => $email->ai_classification,
                'reopened_at' => now()->toIso8601String(),
                'reopened_by_user_id' => auth()->id(),
                'request_id' => $req->id,
            ];
            $email->forceFill(['detected_artifacts' => $existing])->save();

            return $req;
        });

        ParseRequestItemsJob::dispatch($email->id);

        Log::info('MailReview: email reopened as Request', [
            'email_message_id' => $email->id,
            'request_id' => $request->id,
            'overrode_ai_classification' => $email->ai_classification,
            'by_user_id' => auth()->id(),
        ]);

        session()->flash('status', sprintf(
            'Создана заявка %s. Парсер позиций запущен.',
            $request->internal_code,
        ));
    }

    /**
     * Подтвердить AI-решение «отклонить» — пометить письмо как просмотренное
     * (audit для статистики). Реально ничего не меняет в флаге, просто
     * пишет в detected_artifacts что РОП согласен.
     */
    public function confirmRejection(int $emailId): void
    {
        $email = EmailMessage::find($emailId);
        if (! $email) {
            return;
        }
        $existing = is_array($email->detected_artifacts ?? null) ? $email->detected_artifacts : [];
        $existing[] = [
            'type' => 'manual_confirm_rejection',
            'classification' => $email->ai_classification,
            'confirmed_at' => now()->toIso8601String(),
            'confirmed_by_user_id' => auth()->id(),
        ];
        $email->forceFill(['detected_artifacts' => $existing])->save();
    }

    #[Computed]
    public function emails()
    {
        return $this->buildQuery()
            ->with(['mailbox:id,email,owner_user_id'])
            ->withCount('attachments')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Counters per classification — для верхних chip'ов.
     * Считаем в текущем временном окне, чтобы цифры были осмысленные.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counters(): array
    {
        // EmailClassification::Request исключён в buildQuery() — counters
        // показывают все «не-заявочные» классы. Irrelevant — это термин
        // из EmailCategory (3 значения), а не EmailClassification (6).
        $classes = [
            EmailClassification::Reclamation->value,
            EmailClassification::Accounting->value,
            EmailClassification::GeneralQuestion->value,
            EmailClassification::Spam->value,
            EmailClassification::Other->value,
        ];

        $counts = [];
        foreach ($classes as $cls) {
            $counts[$cls] = $this->buildQuery(applyClassification: false)
                ->where('ai_classification', $cls)
                ->count();
        }

        return $counts;
    }

    private function buildQuery(bool $applyClassification = true): Builder
    {
        $q = EmailMessage::query()
            ->where('direction', MailDirection::Inbound->value)
            ->whereNotNull('ai_classification')
            ->whereNull('related_request_id') // только письма БЕЗ связанной заявки
            ->where('ai_classification', '!=', EmailClassification::Request->value);

        if ($applyClassification && $this->classification !== '' && $this->classification !== 'all') {
            $q->where('ai_classification', $this->classification);
        }

        $since = match ($this->window) {
            'today' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => null,
        };
        if ($since !== null) {
            $q->where('sent_at', '>=', $since);
        }

        if ($this->search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->search) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('subject', 'ilike', $needle)
                    ->orWhere('from_email', 'ilike', $needle)
                    ->orWhere('from_name', 'ilike', $needle);
            });
        }

        return $q;
    }

    public function render()
    {
        return view('livewire.admin.mail-review.index');
    }
}
