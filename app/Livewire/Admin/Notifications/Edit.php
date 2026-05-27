<?php

namespace App\Livewire\Admin\Notifications;

use App\Models\ClientNotificationTemplate;
use App\Models\Request;
use App\Services\Mail\ClientNotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Edit extends Component
{
    public ClientNotificationTemplate $template;

    #[Validate('required|string|max:500')]
    public string $subjectTemplate = '';

    #[Validate('required|string')]
    public string $bodyTemplate = '';

    public ?int $thresholdHours = null;

    public ?int $warningDays = null;

    public ?int $previewRequestId = null;

    public ?string $previewSubject = null;

    public ?string $previewHtml = null;

    public ?string $previewPlain = null;

    public ?string $previewError = null;

    public function mount(ClientNotificationTemplate $template): void
    {
        $this->ensureCanManage();
        $this->template = $template;
        $this->subjectTemplate = $template->subject_template;
        $this->bodyTemplate = $template->body_template;
        $this->thresholdHours = $template->threshold_hours;
        $this->warningDays = $template->warning_days;
    }

    public function save(): void
    {
        $this->ensureCanManage();
        $this->validate();

        $this->template->forceFill([
            'subject_template' => $this->subjectTemplate,
            'body_template' => $this->bodyTemplate,
            'threshold_hours' => $this->thresholdHours,
            'warning_days' => $this->warningDays,
            'updated_by_user_id' => Auth::id(),
        ])->save();

        session()->flash('status', 'Шаблон сохранён.');
    }

    public function preview(ClientNotificationService $notifier): void
    {
        $this->ensureCanManage();
        $this->validate();
        $this->previewError = null;
        $this->previewSubject = null;
        $this->previewHtml = null;
        $this->previewPlain = null;

        $request = $this->previewRequestId
            ? Request::find($this->previewRequestId)
            : Request::query()->whereNotNull('client_email')->orderByDesc('id')->first();

        if (! $request) {
            $this->previewError = 'Не нашли заявку с client_email для превью.';

            return;
        }

        // Применяем UNSAVED-значения формы — превью видит ровно то что в textarea.
        $tempTemplate = clone $this->template;
        $tempTemplate->subject_template = $this->subjectTemplate;
        $tempTemplate->body_template = $this->bodyTemplate;

        try {
            $rendered = $notifier->preview($tempTemplate, $request, $this->fakeExtraForPreview());
            $this->previewSubject = $rendered['subject'];
            $this->previewHtml = $rendered['body_html'];
            $this->previewPlain = $rendered['body_plain'];
            $this->previewRequestId = $request->id;
        } catch (\Throwable $e) {
            $this->previewError = 'Ошибка рендера: ' . $e->getMessage();
        }
    }

    /**
     * Fake-значения для type-specific placeholders, чтобы превью не зиял
     * пустыми `{{ var }}`. Реальные значения подставляются в момент отправки.
     */
    private function fakeExtraForPreview(): array
    {
        return [
            'items_count' => 3,
            'items_summary' => "· Плата управления KONE KM713200G01 — 1 шт\n· Контактор ABB EH40 — 2 шт",
            'days_since_sent' => 3,
            'questions_summary' => "· Уточните точную модель эскалатора\n· Нужна ли копия товарной накладной?",
            'days_since_quoted' => 5,
            'quote_amount' => '142 350,00 ₽',
            'invoice_number' => 'МЗ-358912',
            'invoice_amount' => '142 350,00 ₽',
            'invoice_expires_at' => now()->addDays(3)->format('d.m.Y'),
            'invoice_expired_at' => now()->subDays(2)->format('d.m.Y'),
            'days_until_expiry' => 3,
            'days_since_expiry' => 2,
        ];
    }

    public function render()
    {
        $sampleRequests = Request::query()
            ->whereNotNull('client_email')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'internal_code', 'client_email', 'client_name', 'subject']);

        return view('livewire.admin.notifications.edit', [
            'sampleRequests' => $sampleRequests,
            'placeholders' => $this->template->type->placeholders(),
        ]);
    }

    private function ensureCanManage(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->hasAnyRole(['head_of_sales', 'director', 'admin'])) {
            abort(403);
        }
    }
}
