<?php

namespace App\Livewire\Admin\MailReview;

use App\Enums\EmailCategory;
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
 * Решение «не заявка» теперь принимает только Level-1 категоризатор (gpt-4o).
 * Здесь видим письма с category=irrelevant, у которых нет связанного Request.
 * Иногда AI ошибается (шаблонное тело + вложение) — РОП реоткрывает вручную.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Окно по времени: today / 7d / 30d / 90d / all.
     */
    #[Url(as: 'window')]
    public string $window = '30d';

    /**
     * Хронологическая сортировка по дате письма (sent_at):
     * newest — от поздних к ранним (по умолчанию), oldest — наоборот.
     */
    #[Url(as: 'sort')]
    public string $sort = 'newest';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingWindow(): void
    {
        $this->resetPage();
    }

    public function setWindow(string $window): void
    {
        $this->window = in_array($window, ['today', '7d', '30d', '90d', 'all'], true) ? $window : '30d';
        $this->resetPage();
    }

    /**
     * Установить/переключить хронологическую сортировку по дате письма.
     */
    public function setSort(string $sort): void
    {
        $this->sort = $sort === 'oldest' ? 'oldest' : 'newest';
        $this->resetPage();
    }

    public function toggleSort(): void
    {
        $this->setSort($this->sort === 'newest' ? 'oldest' : 'newest');
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
                'overrode_category' => $email->category,
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
            'overrode_category' => $email->category,
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
            'category' => $email->category,
            'confirmed_at' => now()->toIso8601String(),
            'confirmed_by_user_id' => auth()->id(),
        ];
        $email->forceFill(['detected_artifacts' => $existing])->save();
    }

    #[Computed]
    public function emails()
    {
        // Хронология по дате письма. sent_at nullable → NULLS LAST, чтобы
        // письма без даты не всплывали наверх при desc. id — тай-брейк в ту же
        // сторону (стабильный порядок при равных/пустых датах).
        $dir = $this->sort === 'oldest' ? 'asc' : 'desc';

        return $this->buildQuery()
            ->with(['mailbox:id,email,owner_user_id'])
            ->withCount('attachments')
            ->orderByRaw('sent_at ' . ($dir === 'asc' ? 'asc' : 'desc') . ' nulls last')
            ->orderBy('id', $dir)
            ->paginate(25);
    }

    private function buildQuery(): Builder
    {
        $q = EmailMessage::query()
            ->where('direction', MailDirection::Inbound->value)
            ->whereNotNull('categorized_at')
            ->whereNull('related_request_id')
            ->where('category', EmailCategory::Irrelevant->value);

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
