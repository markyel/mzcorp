<?php

namespace App\Livewire\Suppliers;

use App\Enums\Role;
use App\Livewire\Concerns\RendersEmailBody;
use App\Models\SupplierInquiry;
use App\Services\Supplier\SupplierReplyService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Карточка запроса поставщику: реквизиты поставщика, статус, связанная
 * клиентская заявка (если есть), заметки и тред переписки. Доступ — все роли.
 * Переписка рендерится как в заявке — в sandbox-iframe с полным оформлением
 * письма, свёрнутыми цитатами (RendersEmailBody) и выбором порядка.
 */
class Show extends Component
{
    use RendersEmailBody;
    use WithFileUploads;

    /** @var array загруженные фото/файлы для прикрепления к ответу */
    public $replyFiles = [];

    public SupplierInquiry $inquiry;

    public string $supplier_name = '';
    public string $notes = '';

    /** Порядок треда: asc — сначала старые, desc — сначала новые (per-user). */
    public string $threadSort = 'asc';

    /** Текст ответа поставщику. */
    public string $replyBody = '';

    public function mount(SupplierInquiry $inquiry): void
    {
        abort_unless(auth()->check(), 403);
        $this->inquiry = $inquiry;
        $this->supplier_name = (string) ($inquiry->supplier_name ?? '');
        $this->notes = (string) ($inquiry->notes ?? '');
        $this->threadSort = in_array(auth()->user()?->thread_sort_order, ['asc', 'desc'], true)
            ? auth()->user()->thread_sort_order : 'asc';
    }

    public function toggleSort(): void
    {
        $this->threadSort = $this->threadSort === 'asc' ? 'desc' : 'asc';
        auth()->user()?->forceFill(['thread_sort_order' => $this->threadSort])->save();
        unset($this->messages);
    }

    public function save(): void
    {
        $this->validate([
            'supplier_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ], [], ['supplier_name' => 'название']);

        $this->inquiry->update([
            'supplier_name' => trim($this->supplier_name) !== '' ? trim($this->supplier_name) : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);

        $this->dispatch('toast', message: 'Сохранено.', type: 'success');
    }

    public function toggleStatus(): void
    {
        $this->inquiry->update([
            'status' => $this->inquiry->status === 'closed' ? 'open' : 'closed',
        ]);
        $this->dispatch('toast', message: 'Статус запроса обновлён.', type: 'success');
    }

    /** Ручное напоминание поставщику (Фаза 3.5) — вне расписания. */
    public function remindNow(\App\Services\Supplier\SupplierReminderService $reminder): void
    {
        if ($this->inquiry->status === 'closed') {
            $this->dispatch('toast', message: 'Запрос закрыт — напоминание не отправлено.', type: 'error');

            return;
        }
        $ok = $reminder->remind($this->inquiry, auth()->user());
        $this->inquiry->refresh();
        $this->dispatch(
            'toast',
            message: $ok ? 'Напоминание отправлено поставщику.' : 'Не удалось отправить напоминание (см. лог).',
            type: $ok ? 'success' : 'error',
        );
    }

    /**
     * Кто может отвечать поставщику из системы: автор запроса или
     * привилегированный (РОП/директор/админ). Остальным — переписка read-only.
     */
    #[Computed]
    public function canReply(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        if ((int) $this->inquiry->created_by_user_id === (int) $user->id) {
            return true;
        }

        return (bool) $user->hasAnyRole([
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]);
    }

    /** Ответ менеджера поставщику в треде запроса. */
    public function sendReply(SupplierReplyService $replies): void
    {
        if (! $this->canReply()) {
            abort(403);
        }
        if ($this->inquiry->status === 'closed') {
            $this->dispatch('toast', message: 'Запрос закрыт — сначала откройте его.', type: 'error');

            return;
        }
        $this->validate(
            ['replyBody' => 'nullable|string|max:20000', 'replyFiles.*' => 'file|max:25600'],
            [],
            ['replyBody' => 'текст ответа'],
        );
        if (trim($this->replyBody) === '' && empty($this->replyFiles)) {
            $this->addError('replyBody', 'Введите текст ответа или прикрепите файл.');

            return;
        }

        // Загруженные файлы → staging на local; сервис скопирует их в черновик.
        $extraFiles = [];
        foreach ((array) $this->replyFiles as $tmp) {
            if ($tmp === null) {
                continue;
            }
            $name = $tmp->getClientOriginalName();
            $path = sprintf('mail/supplier-reply-staging/%d/%s', $this->inquiry->id, Str::random(10) . '_' . $name);
            Storage::disk('local')->put($path, $tmp->get());
            $extraFiles[] = ['path' => $path, 'name' => $name, 'mime' => $tmp->getMimeType() ?: 'application/octet-stream', 'size' => $tmp->getSize() ?: 0];
        }

        $result = $replies->reply($this->inquiry, auth()->user(), $this->replyBody, $extraFiles);
        if ($result['success'] ?? false) {
            $this->reset(['replyBody', 'replyFiles']);
            $this->inquiry->refresh();
            unset($this->messages);
            $this->dispatch('toast', message: 'Ответ отправлен поставщику.', type: 'success');
        } else {
            $this->dispatch('toast', message: $result['error'] ?? 'Не удалось отправить ответ.', type: 'error');
        }
    }

    public function deleteInquiry()
    {
        // nullOnDelete: письма открепляются (supplier_inquiry_id → null),
        // сами письма и их category=supplier_reply остаются.
        $this->inquiry->delete();

        return $this->redirectRoute('suppliers.index', navigate: true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\EmailMessage>
     */
    #[Computed]
    public function messages()
    {
        $dir = $this->threadSort === 'desc' ? 'desc' : 'asc';

        return $this->inquiry->messages()
            ->reorder('sent_at', $dir)->orderBy('id', $dir)
            ->with(['attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline'])
            ->get(['id', 'direction', 'from_email', 'from_name', 'subject', 'sent_at', 'body_html', 'body_plain', 'related_request_id']);
    }

    /**
     * Запрошенные позиции + предложения поставщика по ним (Фаза 3.3).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\SupplierInquiryItem>
     */
    #[Computed]
    public function inquiryItems()
    {
        return $this->inquiry->items()
            ->with(['requestItem:id,parsed_name,parsed_article', 'offers'])
            ->get();
    }

    public function render()
    {
        return view('livewire.suppliers.show');
    }
}
