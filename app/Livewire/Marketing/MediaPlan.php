<?php

namespace App\Livewire\Marketing;

use App\Models\MediaChannel;
use App\Models\MediaPublication;
use App\Models\MediaTopic;
use App\Services\Marketing\MediaDataService;
use App\Services\Marketing\MediaMaterialService;
use App\Services\Marketing\MediaProfileReviewService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Медиаплан: каналы, темы и публикации.
 *
 * Живёт отдельным компонентом внутри вкладки «Маркетинг» — рабочее место
 * Workspace и так большое, а здесь свой цикл: завести канал, описать тему,
 * получить черновик, проверить его по медиапрофилю, согласовать, отметить
 * публикацию ссылкой.
 *
 * Публикация наружу отсюда НЕ происходит. Материал готовится и согласуется,
 * а размещает его человек: автопостинг в ВК и Дзен — отдельный шаг со своими
 * ключами и своей ценой ошибки.
 */
class MediaPlan extends Component
{
    public ?string $flash = null;

    public ?string $error = null;

    /* ------------------------------ Каналы ------------------------------ */

    public bool $chForm = false;

    public ?int $chEditId = null;

    public string $chName = '';

    public string $chKind = 'telegram';

    public string $chUrl = '';

    public string $chHandle = '';

    public string $chPerWeek = '';

    public string $chNotes = '';

    /* ------------------------------- Темы ------------------------------- */

    public bool $tpForm = false;

    public ?int $tpEditId = null;

    public string $tpTitle = '';

    public string $tpBrief = '';

    public string $tpSource = 'manual';

    public string $tpCadence = '';

    public string $tpNextDue = '';

    /* --------------------------- Публикации ----------------------------- */

    /** Открытая на редактирование публикация. */
    public ?int $pubId = null;

    public string $pubTitle = '';

    public string $pubBody = '';

    public string $pubUrl = '';

    public string $pubPlannedFor = '';

    /** Выбор канала при генерации черновика по теме. */
    public array $draftChannel = [];

    public bool $showArchive = false;

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

    /* ----------------------------- Данные ------------------------------ */

    /** @return Collection<int, MediaChannel> */
    #[Computed]
    public function channels()
    {
        return MediaChannel::query()
            ->withCount(['publications as published_count' => fn ($q) => $q->where('status', 'published')])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, MediaTopic> */
    #[Computed]
    public function topics()
    {
        return MediaTopic::query()
            ->withCount('publications')
            ->orderByDesc('is_active')
            ->orderByRaw('next_due_on NULLS LAST')
            ->orderBy('title')
            ->get();
    }

    /** @return Collection<int, MediaPublication> */
    #[Computed]
    public function publications()
    {
        return MediaPublication::query()
            ->with(['topic:id,title', 'channel:id,name,kind', 'author:id,name'])
            ->when(! $this->showArchive, fn ($q) => $q->whereNotIn('status', ['published', 'rejected']))
            ->orderByRaw("case status when 'in_review' then 0 when 'draft' then 1 when 'approved' then 2 else 3 end")
            ->orderByRaw('planned_for NULLS LAST')
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    #[Computed]
    public function dueTopics()
    {
        return $this->topics->filter(fn (MediaTopic $t) => $t->isDue());
    }

    /* ----------------------------- Каналы ------------------------------ */

    public function startChannel(): void
    {
        $this->chForm = true;
        $this->chEditId = null;
        $this->chName = '';
        $this->chKind = 'telegram';
        $this->chUrl = '';
        $this->chHandle = '';
        $this->chPerWeek = '';
        $this->chNotes = '';
    }

    public function editChannel(int $id): void
    {
        $ch = MediaChannel::find($id);
        if ($ch === null) {
            return;
        }
        $this->chForm = true;
        $this->chEditId = $ch->id;
        $this->chName = (string) $ch->name;
        $this->chKind = (string) $ch->kind;
        $this->chUrl = (string) $ch->url;
        $this->chHandle = (string) $ch->handle;
        $this->chPerWeek = $ch->posts_per_week !== null ? (string) $ch->posts_per_week : '';
        $this->chNotes = (string) $ch->notes;
    }

    public function cancelChannel(): void
    {
        $this->chForm = false;
        $this->chEditId = null;
    }

    public function saveChannel(): void
    {
        $this->ensureAdmin();
        $name = trim($this->chName);
        if ($name === '') {
            $this->error = 'Нужно название канала.';

            return;
        }

        MediaChannel::updateOrCreate(
            ['id' => $this->chEditId],
            [
                'name' => mb_substr($name, 0, 120),
                'kind' => array_key_exists($this->chKind, MediaChannel::KINDS) ? $this->chKind : 'other',
                'url' => trim($this->chUrl) !== '' ? mb_substr(trim($this->chUrl), 0, 500) : null,
                'handle' => trim($this->chHandle) !== '' ? mb_substr(trim($this->chHandle), 0, 160) : null,
                'posts_per_week' => ctype_digit(trim($this->chPerWeek)) ? (int) $this->chPerWeek : null,
                'notes' => trim($this->chNotes) !== '' ? trim($this->chNotes) : null,
            ] + ($this->chEditId ? [] : ['is_active' => true]),
        );

        $this->flash = $this->chEditId ? 'Канал обновлён.' : 'Канал добавлен.';
        $this->cancelChannel();
        unset($this->channels);
    }

    public function toggleChannel(int $id): void
    {
        $ch = MediaChannel::find($id);
        if ($ch !== null) {
            $ch->forceFill(['is_active' => ! $ch->is_active])->save();
        }
        unset($this->channels);
    }

    /* ------------------------------ Темы ------------------------------- */

    public function startTopic(): void
    {
        $this->tpForm = true;
        $this->tpEditId = null;
        $this->tpTitle = '';
        $this->tpBrief = '';
        $this->tpSource = 'manual';
        $this->tpCadence = '';
        $this->tpNextDue = now()->toDateString();
    }

    public function editTopic(int $id): void
    {
        $t = MediaTopic::find($id);
        if ($t === null) {
            return;
        }
        $this->tpForm = true;
        $this->tpEditId = $t->id;
        $this->tpTitle = (string) $t->title;
        $this->tpBrief = (string) $t->brief;
        $this->tpSource = (string) $t->source;
        $this->tpCadence = $t->cadence_days !== null ? (string) $t->cadence_days : '';
        $this->tpNextDue = $t->next_due_on?->toDateString() ?? '';
    }

    public function cancelTopic(): void
    {
        $this->tpForm = false;
        $this->tpEditId = null;
    }

    public function saveTopic(): void
    {
        $this->ensureAdmin();
        $title = trim($this->tpTitle);
        if ($title === '') {
            $this->error = 'Нужно название темы.';

            return;
        }

        MediaTopic::updateOrCreate(
            ['id' => $this->tpEditId],
            [
                'title' => mb_substr($title, 0, 200),
                'brief' => trim($this->tpBrief) !== '' ? trim($this->tpBrief) : null,
                'source' => array_key_exists($this->tpSource, MediaTopic::SOURCES) ? $this->tpSource : 'manual',
                'cadence_days' => ctype_digit(trim($this->tpCadence)) ? (int) $this->tpCadence : null,
                'next_due_on' => trim($this->tpNextDue) !== '' ? trim($this->tpNextDue) : null,
                'created_by_user_id' => $this->tpEditId ? null : auth()->id(),
            ] + ($this->tpEditId ? [] : ['is_active' => true]),
        );

        $this->flash = $this->tpEditId ? 'Тема обновлена.' : 'Тема добавлена.';
        $this->cancelTopic();
        unset($this->topics, $this->dueTopics);
    }

    public function toggleTopic(int $id): void
    {
        $t = MediaTopic::find($id);
        if ($t !== null) {
            $t->forceFill(['is_active' => ! $t->is_active])->save();
        }
        unset($this->topics, $this->dueTopics);
    }

    /* --------------------------- Публикации ---------------------------- */

    /** Черновик по теме в выбранный канал. */
    public function draft(int $topicId): void
    {
        $this->ensureAdmin();
        $this->flash = null;
        $this->error = null;

        $topic = MediaTopic::find($topicId);
        $channelId = (int) ($this->draftChannel[$topicId] ?? 0);
        $channel = $channelId > 0 ? MediaChannel::find($channelId) : null;

        if ($topic === null || $channel === null) {
            $this->error = 'Выберите канал, в который пишем.';

            return;
        }

        $res = app(MediaMaterialService::class)->draft($topic, $channel, auth()->user());
        if (! $res['ok']) {
            $this->error = $res['message'];

            return;
        }

        $this->flash = $res['message'];
        $this->openPublication((int) $res['publication']->id);
        unset($this->publications, $this->topics, $this->dueTopics);
    }

    public function openPublication(int $id): void
    {
        $pub = MediaPublication::find($id);
        if ($pub === null) {
            return;
        }
        $this->pubId = $pub->id;
        $this->pubTitle = (string) $pub->title;
        $this->pubBody = (string) $pub->body;
        $this->pubUrl = (string) $pub->url;
        $this->pubPlannedFor = $pub->planned_for?->toDateString() ?? '';
    }

    public function closePublication(): void
    {
        $this->pubId = null;
    }

    public function savePublication(): void
    {
        $this->ensureAdmin();
        $pub = MediaPublication::find($this->pubId);
        if ($pub === null) {
            return;
        }

        $pub->forceFill([
            'title' => trim($this->pubTitle) !== '' ? mb_substr(trim($this->pubTitle), 0, 300) : null,
            'body' => trim($this->pubBody) !== '' ? trim($this->pubBody) : null,
            'url' => trim($this->pubUrl) !== '' ? mb_substr(trim($this->pubUrl), 0, 500) : null,
            'planned_for' => trim($this->pubPlannedFor) !== '' ? trim($this->pubPlannedFor) : null,
        ])->save();

        $this->flash = 'Сохранено.';
        unset($this->publications);
    }

    /** Прогнать материал через медиапрофиль — та же матрица, что во вкладке «Проверка». */
    public function checkPublication(): void
    {
        $this->ensureAdmin();
        $this->flash = null;
        $this->error = null;

        $pub = MediaPublication::find($this->pubId);
        if ($pub === null || trim((string) $this->pubBody) === '') {
            $this->error = 'Нечего проверять.';

            return;
        }

        $res = app(MediaProfileReviewService::class)->review(
            'other',
            $this->pubBody,
            $this->pubTitle !== '' ? $this->pubTitle : ($pub->topic?->title ?? 'Публикация'),
            auth()->user(),
        );

        if (! $res['ok']) {
            $this->error = $res['message'];

            return;
        }

        $pub->forceFill([
            'media_profile_review_id' => $res['review']->id,
            'status' => 'in_review',
        ])->save();

        $this->flash = $res['message'] !== '' ? $res['message'] : 'Материал проверен по профилю.';
        unset($this->publications);
    }

    /** Взять правку проверки в работу: переписанный текст становится материалом. */
    public function acceptRewrite(): void
    {
        $pub = MediaPublication::with('review')->find($this->pubId);
        $rewritten = $pub?->review?->rewritten_text;
        if ($rewritten === null || trim((string) $rewritten) === '') {
            $this->error = 'Правки нет.';

            return;
        }

        $this->pubBody = (string) $rewritten;
        $this->savePublication();
        $this->flash = 'Правка перенесена в материал.';
    }

    public function setStatus(int $id, string $status): void
    {
        $this->ensureAdmin();
        $pub = MediaPublication::find($id);
        if ($pub === null || ! array_key_exists($status, MediaPublication::STATUSES)) {
            return;
        }

        $pub->forceFill([
            'status' => $status,
            'published_at' => $status === 'published' ? ($pub->published_at ?? now()) : null,
        ])->save();

        // Опубликовали регулярную тему — двигаем её срок на следующий шаг.
        if ($status === 'published' && $pub->topic?->cadence_days) {
            $base = $pub->topic->next_due_on?->isFuture() ? $pub->topic->next_due_on : now();
            $pub->topic->forceFill([
                'next_due_on' => $base->copy()->addDays((int) $pub->topic->cadence_days)->toDateString(),
            ])->save();
        }

        $this->flash = 'Статус: '.$pub->statusLabel().'.';
        unset($this->publications, $this->topics, $this->dueTopics);
    }

    public function deletePublication(int $id): void
    {
        $this->ensureAdmin();
        MediaPublication::where('id', $id)->delete();
        if ($this->pubId === $id) {
            $this->pubId = null;
        }
        $this->flash = 'Материал удалён.';
        unset($this->publications);
    }

    #[Computed]
    public function openPub(): ?MediaPublication
    {
        return $this->pubId ? MediaPublication::with(['review', 'topic:id,title', 'channel:id,name,kind'])->find($this->pubId) : null;
    }

    public function sourceNote(string $source): string
    {
        return app(MediaDataService::class)->sourceNote($source);
    }

    public function render()
    {
        return view('livewire.marketing.media-plan');
    }
}
