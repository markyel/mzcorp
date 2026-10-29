<?php

namespace App\Livewire\Marketing;

use App\Models\Mailbox;
use App\Models\PressMention;
use App\Models\PressOutlet;
use App\Models\PressRelease;
use App\Services\Marketing\PressMailingService;
use App\Services\Marketing\PressReleaseWriter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Медиаплан → «Пресс-релизы»: инфоповоды, текст релиза, рассылка по базе
 * СМИ и публикации, которые из неё вышли.
 *
 * Рассылка уходит только по кнопке человека и с подтверждением: письмо в
 * редакцию не отзовёшь. Сами письма — PressMailingService, по одному.
 */
class PressReleases extends Component
{
    public ?string $flash = null;

    public ?string $error = null;

    /* ---------------------------- Инфоповоды ---------------------------- */

    public bool $relForm = false;

    public string $newTitle = '';

    public string $newOccasion = '';

    public string $newPlannedFor = '';

    public bool $showArchive = false;

    /** Открытый релиз. */
    public ?int $relId = null;

    public string $relTitle = '';

    public string $relOccasion = '';

    public string $relSubject = '';

    public string $relCover = '';

    public string $relBody = '';

    public string $relPlannedFor = '';

    /* ----------------------------- Рассылка ----------------------------- */

    public string $sendMailbox = '';

    /** @var list<string> */
    public array $sendPriorities = ['A'];

    /** @var list<string> пусто — все категории */
    public array $sendCategories = [];

    public string $testEmail = '';

    /* ---------------------------- Публикации ---------------------------- */

    public bool $menForm = false;

    public string $menUrl = '';

    public string $menTitle = '';

    public string $menDate = '';

    public string $menOutlet = '';

    public string $menRelease = '';

    /* ----------------------------- База СМИ ----------------------------- */

    public string $outCategory = '';

    public string $outPriority = '';

    public string $outSearch = '';

    public bool $outForm = false;

    public ?int $outEditId = null;

    public string $outName = '';

    public string $outUrl = '';

    public string $outCat = 'Лифтовая отрасль';

    public string $outPri = 'B';

    public string $outEmails = '';

    public string $outNotes = '';

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->testEmail = (string) auth()->user()?->email;
        $this->sendMailbox = (string) (app(PressMailingService::class)->defaultMailbox()?->id ?? '');
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

    /** @return Collection<int, PressRelease> */
    #[Computed]
    public function releases()
    {
        return PressRelease::query()
            ->withCount([
                'sends',
                'sends as replied_count' => fn ($q) => $q->where('status', 'replied'),
                'sends as failed_count' => fn ($q) => $q->where('status', 'failed'),
                'mentions',
            ])
            ->when(! $this->showArchive, fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderByRaw("case status when 'ready' then 0 when 'draft' then 1 when 'idea' then 2 when 'sent' then 3 else 4 end")
            ->orderByRaw('planned_for NULLS LAST')
            ->orderByDesc('id')
            ->limit(80)
            ->get();
    }

    #[Computed]
    public function openRelease(): ?PressRelease
    {
        return $this->relId
            ? PressRelease::with(['sends' => fn ($q) => $q->with(['outlet:id,name,url', 'reply:id,subject,from_email,from_name,sent_at'])->orderBy('id'), 'mentions.outlet:id,name'])->find($this->relId)
            : null;
    }

    /** @return Collection<int, Mailbox> */
    #[Computed]
    public function mailboxes()
    {
        return app(PressMailingService::class)->senderMailboxes();
    }

    /** Сколько редакций получит релиз при текущем выборе (кроме тех, кому уже ушёл). */
    #[Computed]
    public function audienceCount(): int
    {
        if ($this->relId === null || $this->sendPriorities === []) {
            return 0;
        }
        $already = $this->openRelease?->sends->pluck('press_outlet_id')->all() ?? [];

        return app(PressMailingService::class)
            ->audience(array_values($this->sendPriorities), array_values($this->sendCategories))
            ->reject(fn (PressOutlet $o) => in_array($o->id, $already, true))
            ->count();
    }

    /** @return Collection<int, PressOutlet> */
    #[Computed]
    public function outlets()
    {
        $search = trim($this->outSearch);

        return PressOutlet::query()
            ->withCount([
                'sends',
                'sends as replied_count' => fn ($q) => $q->where('status', 'replied'),
                'mentions',
            ])
            ->when($this->outCategory !== '', fn ($q) => $q->where('category', $this->outCategory))
            ->when($this->outPriority !== '', fn ($q) => $q->where('priority', $this->outPriority))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('url', 'ilike', '%'.$search.'%')
                ->orWhereRaw('emails::text ilike ?', ['%'.$search.'%'])))
            ->orderByDesc('is_active')
            ->orderBy('priority')
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, int> */
    #[Computed]
    public function outletTotals(): array
    {
        return PressOutlet::query()->active()
            ->selectRaw('priority, count(*) as n')
            ->groupBy('priority')
            ->pluck('n', 'priority')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return Collection<int, PressMention> */
    #[Computed]
    public function mentions()
    {
        return PressMention::query()
            ->with(['outlet:id,name', 'release:id,title'])
            ->orderByRaw('published_on DESC NULLS LAST')
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    /* ---------------------------- Инфоповоды ---------------------------- */

    public function startRelease(): void
    {
        $this->relForm = true;
        $this->newTitle = '';
        $this->newOccasion = '';
        $this->newPlannedFor = '';
    }

    public function cancelRelease(): void
    {
        $this->relForm = false;
    }

    public function createRelease(): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $title = trim($this->newTitle);
        if ($title === '') {
            $this->error = 'Назовите инфоповод.';

            return;
        }

        $release = PressRelease::create([
            'title' => mb_substr($title, 0, 300),
            'occasion' => trim($this->newOccasion) !== '' ? trim($this->newOccasion) : null,
            'planned_for' => trim($this->newPlannedFor) !== '' ? trim($this->newPlannedFor) : null,
            'status' => 'idea',
            'created_by_user_id' => auth()->id(),
        ]);

        $this->relForm = false;
        $this->flash = 'Инфоповод добавлен.';
        $this->open($release->id);
        unset($this->releases);
    }

    public function open(int $id): void
    {
        $r = PressRelease::find($id);
        if ($r === null) {
            return;
        }
        $this->relId = $r->id;
        $this->relTitle = (string) $r->title;
        $this->relOccasion = (string) $r->occasion;
        $this->relSubject = (string) $r->subject;
        $this->relCover = (string) $r->cover_note;
        $this->relBody = (string) $r->body;
        $this->relPlannedFor = $r->planned_for?->toDateString() ?? '';
        if ($r->mailbox_id) {
            $this->sendMailbox = (string) $r->mailbox_id;
        }
        unset($this->openRelease, $this->audienceCount);
    }

    public function close(): void
    {
        $this->relId = null;
    }

    public function save(): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $r = PressRelease::find($this->relId);
        if ($r === null) {
            return;
        }

        $r->forceFill([
            'title' => trim($this->relTitle) !== '' ? mb_substr(trim($this->relTitle), 0, 300) : $r->title,
            'occasion' => trim($this->relOccasion) !== '' ? trim($this->relOccasion) : null,
            'subject' => trim($this->relSubject) !== '' ? mb_substr(trim($this->relSubject), 0, 300) : null,
            'cover_note' => trim($this->relCover) !== '' ? trim($this->relCover) : null,
            'body' => trim($this->relBody) !== '' ? trim($this->relBody) : null,
            'planned_for' => trim($this->relPlannedFor) !== '' ? trim($this->relPlannedFor) : null,
        ])->save();

        // Появился текст — инфоповод стал черновиком.
        if ($r->status === 'idea' && trim((string) $r->body) !== '') {
            $r->forceFill(['status' => 'draft'])->save();
        }

        $this->flash = 'Сохранено.';
        unset($this->releases, $this->openRelease);
    }

    /** Черновик релиза по инфоповоду (модель). */
    public function write(): void
    {
        $this->ensureAdmin();
        $this->save();
        $this->resetMessages();
        $r = PressRelease::find($this->relId);
        if ($r === null) {
            return;
        }
        if ($r->status === 'sent') {
            $this->error = 'Релиз уже разослан — переписывать его поздно.';

            return;
        }

        $res = app(PressReleaseWriter::class)->write($r);
        $res['ok'] ? $this->flash = $res['message'] : $this->error = $res['message'];
        $this->open($r->id);
        unset($this->releases);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $r = PressRelease::withCount('sends')->find($id);
        if ($r === null || ! array_key_exists($status, PressRelease::STATUSES)) {
            return;
        }
        // «Разослан» ставит только сама рассылка; вручную — лишь возврат из архива.
        if ($status === 'sent' && $r->sends_count === 0) {
            return;
        }
        if ($status === 'ready' && ! $r->isSendable()) {
            $this->error = 'Для рассылки нужны тема письма и текст релиза.';

            return;
        }

        $r->forceFill(['status' => $status])->save();
        $this->flash = 'Статус: '.$r->statusLabel().'.';
        unset($this->releases, $this->openRelease);
    }

    public function delete(int $id): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $r = PressRelease::withCount('sends')->find($id);
        if ($r === null) {
            return;
        }
        if ($r->sends_count > 0) {
            $this->error = 'Релиз уже рассылался — его можно убрать в архив, но не удалить.';

            return;
        }

        $r->delete();
        if ($this->relId === $id) {
            $this->relId = null;
        }
        $this->flash = 'Удалено.';
        unset($this->releases);
    }

    /* ----------------------------- Рассылка ----------------------------- */

    public function updatedSendPriorities(): void
    {
        unset($this->audienceCount);
    }

    public function updatedSendCategories(): void
    {
        unset($this->audienceCount);
    }

    private function senderMailbox(): ?Mailbox
    {
        return $this->mailboxes->firstWhere('id', (int) $this->sendMailbox);
    }

    public function sendTest(): void
    {
        $this->ensureAdmin();
        $this->save();
        $this->resetMessages();
        $r = PressRelease::find($this->relId);
        $mailbox = $this->senderMailbox();
        if ($r === null || $mailbox === null) {
            $this->error = 'Выберите ящик-отправитель.';

            return;
        }

        $res = app(PressMailingService::class)->sendTest($r, $mailbox, auth()->user(), trim($this->testEmail));
        $res['ok'] ? $this->flash = $res['message'] : $this->error = $res['message'];
    }

    public function send(): void
    {
        $this->ensureAdmin();
        $this->save();
        $this->resetMessages();
        $r = PressRelease::find($this->relId);
        $mailbox = $this->senderMailbox();
        if ($r === null || $mailbox === null) {
            $this->error = 'Выберите ящик-отправитель.';

            return;
        }
        if ($this->sendPriorities === []) {
            $this->error = 'Отметьте хотя бы один приоритет площадок.';

            return;
        }

        $service = app(PressMailingService::class);
        $outlets = $service->audience(array_values($this->sendPriorities), array_values($this->sendCategories));
        $res = $service->queue($r, $outlets, $mailbox, auth()->user());
        $res['ok'] ? $this->flash = $res['message'] : $this->error = $res['message'];
        unset($this->releases, $this->openRelease, $this->audienceCount, $this->outlets);
    }

    public function retryFailed(): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $r = PressRelease::find($this->relId);
        $mailbox = $this->senderMailbox();
        if ($r === null || $mailbox === null) {
            return;
        }

        $n = app(PressMailingService::class)->retryFailed($r, $mailbox, auth()->user());
        $this->flash = $n > 0 ? 'Повторно в очереди: '.$n.'.' : 'Неушедших писем нет.';
        unset($this->openRelease, $this->releases);
    }

    /** Опрос карточки, пока письма рассылки уходят. */
    public function refreshSends(): void
    {
        unset($this->openRelease, $this->releases);
    }

    /* ---------------------------- Публикации ---------------------------- */

    public function startMention(?int $releaseId = null): void
    {
        $this->menForm = true;
        $this->menUrl = '';
        $this->menTitle = '';
        $this->menDate = now()->toDateString();
        $this->menOutlet = '';
        $this->menRelease = $releaseId ? (string) $releaseId : '';
    }

    public function cancelMention(): void
    {
        $this->menForm = false;
    }

    public function saveMention(): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $url = trim($this->menUrl);
        if (! preg_match('~^https?://~i', $url)) {
            $this->error = 'Нужна ссылка на публикацию (http…).';

            return;
        }

        // Площадка не выбрана — пробуем узнать её по домену ссылки.
        $outletId = ctype_digit($this->menOutlet) ? (int) $this->menOutlet : null;
        if ($outletId === null) {
            $host = preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
            $outletId = $host !== ''
                ? PressOutlet::query()->get(['id', 'url'])->first(fn (PressOutlet $o) => $o->host() === $host)?->id
                : null;
        }

        PressMention::create([
            'url' => mb_substr($url, 0, 500),
            'title' => trim($this->menTitle) !== '' ? mb_substr(trim($this->menTitle), 0, 300) : null,
            'published_on' => trim($this->menDate) !== '' ? trim($this->menDate) : null,
            'press_outlet_id' => $outletId,
            'press_release_id' => ctype_digit($this->menRelease) ? (int) $this->menRelease : null,
            'created_by_user_id' => auth()->id(),
        ]);

        $this->menForm = false;
        $this->flash = 'Публикация добавлена.';
        unset($this->mentions, $this->releases, $this->openRelease, $this->outlets);
    }

    public function deleteMention(int $id): void
    {
        $this->ensureAdmin();
        PressMention::where('id', $id)->delete();
        unset($this->mentions, $this->releases, $this->openRelease, $this->outlets);
    }

    /* ----------------------------- База СМИ ----------------------------- */

    public function startOutlet(): void
    {
        $this->outForm = true;
        $this->outEditId = null;
        $this->outName = '';
        $this->outUrl = '';
        $this->outCat = PressOutlet::CATEGORIES[0];
        $this->outPri = 'B';
        $this->outEmails = '';
        $this->outNotes = '';
    }

    public function editOutlet(int $id): void
    {
        $o = PressOutlet::find($id);
        if ($o === null) {
            return;
        }
        $this->outForm = true;
        $this->outEditId = $o->id;
        $this->outName = (string) $o->name;
        $this->outUrl = (string) $o->url;
        $this->outCat = (string) $o->category;
        $this->outPri = (string) $o->priority;
        $this->outEmails = implode(', ', $o->emails ?? []);
        $this->outNotes = (string) $o->notes;
    }

    public function cancelOutlet(): void
    {
        $this->outForm = false;
        $this->outEditId = null;
    }

    public function saveOutlet(): void
    {
        $this->ensureAdmin();
        $this->resetMessages();
        $name = trim($this->outName);
        $url = trim($this->outUrl);
        if ($name === '' || $url === '') {
            $this->error = 'Нужны название и сайт площадки.';

            return;
        }

        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $this->outEmails, $m);
        $emails = array_values(array_unique(array_map('strtolower', $m[0])));

        $dupe = PressOutlet::where('url', $url)->when($this->outEditId, fn ($q) => $q->where('id', '!=', $this->outEditId))->exists();
        if ($dupe) {
            $this->error = 'Площадка с таким сайтом уже есть в базе.';

            return;
        }

        PressOutlet::updateOrCreate(
            ['id' => $this->outEditId],
            [
                'name' => mb_substr($name, 0, 200),
                'url' => mb_substr($url, 0, 500),
                'category' => in_array($this->outCat, PressOutlet::CATEGORIES, true) ? $this->outCat : PressOutlet::CATEGORIES[0],
                'priority' => array_key_exists($this->outPri, PressOutlet::PRIORITIES) ? $this->outPri : 'B',
                'emails' => $emails ?: null,
                'notes' => trim($this->outNotes) !== '' ? trim($this->outNotes) : null,
            ] + ($this->outEditId ? [] : ['is_active' => true]),
        );

        $this->flash = $this->outEditId ? 'Площадка обновлена.' : 'Площадка добавлена.';
        $this->cancelOutlet();
        unset($this->outlets, $this->outletTotals, $this->audienceCount);
    }

    public function setPriority(int $id, string $priority): void
    {
        $this->ensureAdmin();
        if (! array_key_exists($priority, PressOutlet::PRIORITIES)) {
            return;
        }
        PressOutlet::where('id', $id)->update(['priority' => $priority]);
        unset($this->outlets, $this->outletTotals, $this->audienceCount);
    }

    public function toggleOutlet(int $id): void
    {
        $this->ensureAdmin();
        $o = PressOutlet::find($id);
        $o?->forceFill(['is_active' => ! $o->is_active])->save();
        unset($this->outlets, $this->outletTotals, $this->audienceCount);
    }

    public function render()
    {
        return view('livewire.marketing.press-releases');
    }
}
