<?php

namespace App\Livewire\Admin\Marketing;

use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\MarketingBlock;
use App\Services\Mail\OutgoingMailMimeBuilder;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Marketing\MarketingBlockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Управление рекламными блоками в письмах клиентам (директорат / админ).
 *
 * Список + форма добавления/редактирования с живым превью (образец письма +
 * подпись + блок в iframe) и тестовой отправкой на заданный адрес с выбранного
 * ящика. Картинка — PNG/JPG до 512 КБ (email-клиенты не показывают WEBP/SVG),
 * хранится на private-диске, в письмо встраивается как CID.
 */
class Blocks extends Component
{
    use WithFileUploads;

    public bool $showForm = false;

    public ?int $editId = null;

    public string $title = '';

    public string $text = '';

    public string $url = '';

    public bool $isActive = true;

    /** Новая картинка (TemporaryUploadedFile) — при создании обязательна. */
    public $image = null;

    public ?int $testMailboxId = null;

    public string $testEmail = '';

    public ?string $flashMessage = null;

    public ?string $flashError = null;

    public function mount(): void
    {
        $this->ensureCanManage();
        $this->testEmail = (string) (Auth::user()?->email ?? '');
    }

    #[Computed]
    public function blocks()
    {
        return MarketingBlock::query()->with('createdBy')
            ->orderByDesc('is_active')->orderByDesc('id')->get();
    }

    /** Ящики, с которых можно отправить тест (активные, с OAuth/паролем). */
    #[Computed]
    public function senders()
    {
        return Mailbox::query()->where('is_active', true)->with('owner')->orderBy('email')->get()
            ->filter(fn (Mailbox $m) => $m->canSendOutbound())
            ->values();
    }

    #[Computed]
    public function editing(): ?MarketingBlock
    {
        return $this->editId ? MarketingBlock::query()->find($this->editId) : null;
    }

    /** Полный HTML для iframe-превью по текущим полям формы. */
    #[Computed]
    public function previewHtml(): string
    {
        $mailbox = $this->testMailboxId ? Mailbox::query()->with('owner')->find($this->testMailboxId) : null;
        $author = ($mailbox && $mailbox->type === MailboxType::Personal && $mailbox->owner) ? $mailbox->owner : Auth::user();

        return app(MarketingBlockService::class)->previewDocument([
            'title' => $this->title,
            'text' => $this->text,
            'url' => $this->url,
            'image_url' => $this->currentImageUrl(),
        ], $author);
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $block = MarketingBlock::query()->find($id);
        if (! $block) {
            return;
        }
        $this->resetForm();
        $this->editId = $block->id;
        $this->title = $block->title;
        $this->text = $block->text;
        $this->url = $block->url;
        $this->isActive = $block->is_active;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(): void
    {
        $this->ensureCanManage();
        $this->flashMessage = null;
        $this->flashError = null;
        $this->validate($this->rules());

        $block = $this->editing ?? new MarketingBlock;
        $isNew = ! $block->exists;

        $data = [
            'title' => trim($this->title),
            'text' => trim($this->text),
            'url' => trim($this->url),
            'is_active' => $this->isActive,
        ];
        if ($isNew) {
            $data['created_by_user_id'] = Auth::id();
        }

        if ($this->image) {
            // Метаданные — ДО storeAs (после него TemporaryUploadedFile теряет файл).
            $ext = strtolower($this->image->getClientOriginalExtension() ?: 'png');
            $ext = $ext === 'jpeg' ? 'jpg' : $ext;
            $name = Str::random(12).'.'.$ext;
            $newPath = $this->image->storeAs(MarketingBlock::IMAGE_DIR, $name, MarketingBlock::IMAGE_DISK);

            // storeAs возвращает путь, даже если запись не удалась (Flysystem
            // put() при ошибке отдаёт false без исключения — например, каталог
            // принадлежит другому пользователю и php-fpm в него не пишет).
            // Кейс 04.09.2026: блок сохранился с image_path, файла на диске нет,
            // в письмах и превью — битая картинка. Проверяем факт записи.
            if (! Storage::disk(MarketingBlock::IMAGE_DISK)->exists($newPath)) {
                Log::error('MarketingBlocks: image not written to disk', [
                    'path' => $newPath,
                    'disk' => MarketingBlock::IMAGE_DISK,
                    'dir' => Storage::disk(MarketingBlock::IMAGE_DISK)->path(MarketingBlock::IMAGE_DIR),
                ]);
                $this->flashError = 'Картинка не записалась на диск (нет прав на каталог storage/app/private/marketing). Блок не сохранён — сообщите администратору.';

                return;
            }

            $block->deleteImageFile();
            $data['image_path'] = $newPath;
            $this->image = null;
        }

        $block->fill($data)->save();

        $this->flashMessage = $isNew ? "Блок «{$block->title}» добавлен." : "Блок «{$block->title}» сохранён.";
        $this->resetForm();
        $this->showForm = false;
        unset($this->blocks);
    }

    public function toggleActive(int $id): void
    {
        $this->ensureCanManage();
        $block = MarketingBlock::query()->find($id);
        if (! $block) {
            return;
        }
        $block->forceFill(['is_active' => ! $block->is_active])->save();
        unset($this->blocks);
    }

    public function delete(int $id): void
    {
        $this->ensureCanManage();
        $block = MarketingBlock::query()->find($id);
        if (! $block) {
            return;
        }
        $title = $block->title;
        $block->deleteImageFile();
        $block->delete();
        if ($this->editId === $id) {
            $this->cancel();
        }
        $this->flashMessage = "Блок «{$title}» удалён.";
        unset($this->blocks);
    }

    /** Тестовое письмо по текущим полям формы (блок можно ещё не сохранять). */
    public function sendTest(): void
    {
        $this->ensureCanManage();
        $this->flashMessage = null;
        $this->flashError = null;
        $this->validate(array_merge($this->rules(requireImage: false), [
            'testMailboxId' => 'required|integer|exists:mailboxes,id',
            'testEmail' => 'required|email|max:255',
        ]));

        $mailbox = Mailbox::query()->with('owner')->find($this->testMailboxId);
        if (! $mailbox || ! $mailbox->canSendOutbound()) {
            $this->flashError = 'Выбранный ящик не может отправлять письма.';

            return;
        }

        $payload = [
            'title' => trim($this->title),
            'text' => trim($this->text),
            'url' => trim($this->url),
            'image_url' => $this->currentImageUrl(),
            'image_path' => $this->currentImagePath(),
        ];
        if ($payload['image_url'] === null) {
            $this->flashError = 'Загрузите картинку — без неё тест не показателен.';

            return;
        }

        try {
            app(MarketingBlockService::class)->sendTest(
                $payload,
                $mailbox,
                mb_strtolower(trim($this->testEmail)),
                Auth::user(),
                app(OutgoingMailMimeBuilder::class),
                app(OutgoingMailSender::class),
            );
            $this->flashMessage = "Тестовое письмо отправлено на {$this->testEmail} с ящика {$mailbox->email}.";
        } catch (\Throwable $e) {
            Log::warning('MarketingBlocks: test send failed', [
                'mailbox_id' => $mailbox->id,
                'to' => $this->testEmail,
                'error' => $e->getMessage(),
            ]);
            $this->flashError = 'Не удалось отправить: '.$e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.admin.marketing.blocks');
    }

    /** @return array<string, string> */
    private function rules(?bool $requireImage = null): array
    {
        $requireImage ??= $this->editId === null;

        return [
            'title' => 'required|string|max:120',
            'text' => 'required|string|max:300',
            'url' => 'required|url|max:500',
            'isActive' => 'boolean',
            'image' => ($requireImage ? 'required' : 'nullable')
                .'|image|mimes:png,jpg,jpeg|max:512|dimensions:max_width=1600,max_height=1600',
        ];
    }

    /** URL картинки для превью: свежая загрузка (temporary URL) или сохранённая. */
    private function currentImageUrl(): ?string
    {
        if ($this->image) {
            try {
                return $this->image->temporaryUrl();
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->editing?->imageUrl();
    }

    private function currentImagePath(): ?string
    {
        if ($this->image) {
            $path = $this->image->getRealPath();

            return is_string($path) && $path !== '' ? $path : null;
        }

        return $this->editing?->imageLocalPath();
    }

    private function resetForm(): void
    {
        $this->reset(['editId', 'title', 'text', 'url', 'image']);
        $this->isActive = true;
        $this->resetValidation();
        unset($this->editing);
    }

    private function ensureCanManage(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->hasAnyRole(['director', 'admin'])) {
            abort(403);
        }
    }
}
