<?php

namespace App\Livewire\Marketing;

use App\Models\MarketingAssignment;
use App\Models\MarketingAssignmentFile;
use App\Services\Marketing\MarketingAssignmentExportService;
use App\Services\Marketing\MarketingAssignmentWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Маркетинг → «Поручения»: задачи соседним отделам (завести ящик, сверстать
 * страницу, выложить пресс-кит). У исполнителей нет доступа в MyLift, поэтому
 * поручение — подробная инструкция с файлами, которая выгружается архивом
 * (PDF + HTML + материалы), а статус и итог ведутся здесь.
 */
class Assignments extends Component
{
    use WithFileUploads;

    public ?string $flash = null;

    public ?string $error = null;

    public bool $showClosed = false;

    public bool $newForm = false;

    public string $newTitle = '';

    public string $newExecutor = '';

    public string $newBrief = '';

    public string $newDue = '';

    /** Открытое поручение. */
    public ?int $openId = null;

    public string $title = '';

    public string $executor = '';

    public string $executorContact = '';

    public string $brief = '';

    public string $instructions = '';

    public string $due = '';

    public string $priority = '2';

    public string $resultNotes = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public string $uploadNote = '';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasRole('admin')) {
            abort(403);
        }
    }

    private function resetMessages(): void
    {
        $this->flash = null;
        $this->error = null;
    }

    /* ----------------------------- Данные ------------------------------ */

    /** @return Collection<int, MarketingAssignment> */
    #[Computed]
    public function assignments()
    {
        return MarketingAssignment::query()
            ->withCount('files')
            ->when(! $this->showClosed, fn ($q) => $q->whereIn('status', ['draft', 'handed', 'in_progress']))
            ->orderByRaw("case status when 'in_progress' then 0 when 'handed' then 1 when 'draft' then 2 when 'done' then 3 else 4 end")
            ->orderBy('priority')
            ->orderByRaw('due_on NULLS LAST')
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function open(): ?MarketingAssignment
    {
        return $this->openId ? MarketingAssignment::with(['files', 'author:id,name,email'])->find($this->openId) : null;
    }

    #[Computed]
    public function previewHtml(): string
    {
        $a = $this->open;

        return $a ? app(MarketingAssignmentExportService::class)->instructionsHtml($a) : '';
    }

    /* ---------------------------- Поручения ---------------------------- */

    public function startNew(): void
    {
        $this->newForm = true;
        $this->newTitle = '';
        $this->newExecutor = '';
        $this->newBrief = '';
        $this->newDue = '';
    }

    public function cancelNew(): void
    {
        $this->newForm = false;
    }

    public function create(): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        if (trim($this->newTitle) === '') {
            $this->error = 'Назовите поручение.';

            return;
        }

        $a = MarketingAssignment::create([
            'title' => mb_substr(trim($this->newTitle), 0, 300),
            'executor' => trim($this->newExecutor) !== '' ? mb_substr(trim($this->newExecutor), 0, 200) : null,
            'brief' => trim($this->newBrief) !== '' ? trim($this->newBrief) : null,
            'due_on' => trim($this->newDue) !== '' ? trim($this->newDue) : null,
            'status' => 'draft',
            'priority' => 2,
            'created_by_user_id' => auth()->id(),
        ]);

        $this->newForm = false;
        $this->flash = 'Поручение заведено. Приложите файлы и распишите инструкцию.';
        $this->openAssignment($a->id);
        unset($this->assignments);
    }

    public function openAssignment(int $id): void
    {
        $a = MarketingAssignment::find($id);
        if ($a === null) {
            return;
        }
        $this->openId = $a->id;
        $this->title = (string) $a->title;
        $this->executor = (string) $a->executor;
        $this->executorContact = (string) $a->executor_contact;
        $this->brief = (string) $a->brief;
        $this->instructions = (string) $a->instructions;
        $this->due = $a->due_on?->toDateString() ?? '';
        $this->priority = (string) $a->priority;
        $this->resultNotes = (string) $a->result_notes;
        $this->uploads = [];
        $this->uploadNote = '';
        unset($this->open, $this->previewHtml);
    }

    public function closeAssignment(): void
    {
        $this->openId = null;
    }

    public function save(): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $a = MarketingAssignment::find($this->openId);
        if ($a === null) {
            return;
        }

        $a->forceFill([
            'title' => trim($this->title) !== '' ? mb_substr(trim($this->title), 0, 300) : $a->title,
            'executor' => trim($this->executor) !== '' ? mb_substr(trim($this->executor), 0, 200) : null,
            'executor_contact' => trim($this->executorContact) !== '' ? mb_substr(trim($this->executorContact), 0, 200) : null,
            'brief' => trim($this->brief) !== '' ? trim($this->brief) : null,
            'instructions' => trim($this->instructions) !== '' ? trim($this->instructions) : null,
            'due_on' => trim($this->due) !== '' ? trim($this->due) : null,
            'priority' => array_key_exists((int) $this->priority, MarketingAssignment::PRIORITIES) ? (int) $this->priority : 2,
            'result_notes' => trim($this->resultNotes) !== '' ? trim($this->resultNotes) : null,
        ])->save();

        $this->flash = 'Сохранено.';
        unset($this->assignments, $this->open, $this->previewHtml);
    }

    /** Подробная инструкция по постановке (модель). */
    public function write(): void
    {
        $this->ensureAdmin();
        $this->save();
        $this->resetMessages();
        $a = MarketingAssignment::find($this->openId);
        if ($a === null) {
            return;
        }

        $res = app(MarketingAssignmentWriter::class)->write($a);
        $res['ok'] ? $this->flash = $res['message'] : $this->error = $res['message'];
        $this->instructions = (string) $a->fresh()->instructions;
        unset($this->open, $this->previewHtml);
    }

    public function setStatus(string $status): void
    {
        $this->ensureAdmin();
        $this->save();
        $this->resetMessages();
        $a = MarketingAssignment::find($this->openId);
        if ($a === null || ! array_key_exists($status, MarketingAssignment::STATUSES)) {
            return;
        }

        $a->forceFill([
            'status' => $status,
            'handed_at' => in_array($status, ['handed', 'in_progress', 'done'], true) ? ($a->handed_at ?? now()) : $a->handed_at,
            'done_at' => $status === 'done' ? ($a->done_at ?? now()) : null,
        ])->save();

        $this->flash = 'Статус: '.$a->statusLabel().'.';
        unset($this->assignments, $this->open);
    }

    public function delete(): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $a = MarketingAssignment::with('files')->find($this->openId);
        if ($a === null) {
            return;
        }

        foreach ($a->files as $file) {
            $file->deleteFile();
        }
        $a->delete();
        $this->openId = null;
        $this->flash = 'Поручение удалено.';
        unset($this->assignments);
    }

    /* ------------------------------ Файлы ------------------------------ */

    public function updatedUploads(): void
    {
        $this->resetMessages();
        $this->validate(['uploads.*' => ['file', 'max:29696']], [], ['uploads.*' => 'файл']);

        $a = MarketingAssignment::find($this->openId);
        if ($a === null) {
            return;
        }

        $added = 0;
        foreach ($this->uploads as $upload) {
            // Метаданные — до storeAs: после него временный файл исчезает.
            $original = (string) $upload->getClientOriginalName();
            $mime = $upload->getMimeType();
            $size = (int) $upload->getSize();
            $path = $upload->storeAs(
                MarketingAssignmentFile::DIR.'/'.$a->id,
                Str::random(10).'_'.Str::limit(Str::slug(pathinfo($original, PATHINFO_FILENAME)), 60, '').'.'.strtolower($upload->getClientOriginalExtension() ?: 'bin'),
                MarketingAssignmentFile::DISK,
            );
            if (! $path || ! Storage::disk(MarketingAssignmentFile::DISK)->exists($path)) {
                Log::error('MarketingAssignments: file not written', ['assignment_id' => $a->id, 'file' => $original]);
                $this->error = 'Файл «'.$original.'» не записался на диск — сообщите администратору.';

                continue;
            }

            MarketingAssignmentFile::create([
                'marketing_assignment_id' => $a->id,
                'filename' => mb_substr($original, 0, 255),
                'path' => $path,
                'mime' => $mime ? mb_substr($mime, 0, 120) : null,
                'size' => $size,
                'note' => trim($this->uploadNote) !== '' ? mb_substr(trim($this->uploadNote), 0, 300) : null,
                'uploaded_by_user_id' => auth()->id(),
            ]);
            $added++;
        }

        $this->uploads = [];
        $this->uploadNote = '';
        if ($added > 0) {
            $this->flash = 'Файлов добавлено: '.$added.'.';
        }
        unset($this->open, $this->assignments);
    }

    public function deleteFile(int $fileId): void
    {
        $this->ensureAdmin();
        $file = MarketingAssignmentFile::where('marketing_assignment_id', $this->openId)->find($fileId);
        if ($file === null) {
            return;
        }
        $file->deleteFile();
        $file->delete();
        unset($this->open, $this->assignments);
    }

    public function downloadFile(int $fileId): ?StreamedResponse
    {
        $this->ensureAdmin();
        $file = MarketingAssignmentFile::where('marketing_assignment_id', $this->openId)->find($fileId);
        $contents = $file?->contents();
        if ($contents === null) {
            $this->error = 'Файла нет на диске.';

            return null;
        }

        return response()->streamDownload(fn () => print ($contents), $file->filename, [
            'Content-Type' => $file->mime ?: 'application/octet-stream',
        ]);
    }

    /* ----------------------------- Выгрузка ---------------------------- */

    public function exportZip(): ?StreamedResponse
    {
        return $this->export('zip');
    }

    public function exportPdf(): ?StreamedResponse
    {
        return $this->export('pdf');
    }

    private function export(string $kind): ?StreamedResponse
    {
        $this->ensureAdmin();
        $this->save();
        $this->resetMessages();
        $a = MarketingAssignment::with(['files', 'author'])->find($this->openId);
        if ($a === null) {
            return null;
        }
        if (trim((string) $a->instructions) === '' && trim((string) $a->brief) === '') {
            $this->error = 'Инструкция пуста — выгружать нечего.';

            return null;
        }

        $service = app(MarketingAssignmentExportService::class);
        try {
            $bytes = $kind === 'zip' ? $service->zip($a) : $service->pdf($a);
        } catch (\Throwable $e) {
            Log::error('MarketingAssignments: export failed', ['assignment_id' => $a->id, 'kind' => $kind, 'error' => $e->getMessage()]);
            $this->error = 'Не удалось выгрузить: '.$e->getMessage();

            return null;
        }

        return response()->streamDownload(fn () => print ($bytes), $service->filename($a, $kind), [
            'Content-Type' => $kind === 'zip' ? 'application/zip' : 'application/pdf',
        ]);
    }

    public function render()
    {
        return view('livewire.marketing.assignments');
    }
}
