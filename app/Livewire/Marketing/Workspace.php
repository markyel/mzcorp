<?php

namespace App\Livewire\Marketing;

use App\Enums\MarketingSection;
use App\Models\MarketingContact;
use App\Models\MarketingEntry;
use App\Models\MarketingReport;
use App\Models\MarketingService;
use App\Services\Marketing\MarketingReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Раздел «Маркетинг» — рабочее место по договору оказания маркетинговых услуг.
 * ТОЛЬКО для админа: здесь лежат доступы к внешним сервисам.
 *
 * Вкладки:
 *  - «Доступы»  — сервисы и креды (секреты шифрованы, показываются по клику);
 *  - «Книжка»   — подрядчики и площадки по направлениям (контакты, папки);
 *  - «План»     — задачи и заметки на месяц по разделам формы отчёта;
 *  - «Журнал»   — что фактически сделано (из него собирается отчёт);
 *  - «Отчёт»    — форма Приложения № 1 за месяц + выгрузка .docx.
 */
class Workspace extends Component
{
    public const TABS = ['access', 'contacts', 'plan', 'log', 'report', 'profile'];

    #[Url(as: 'tab', except: 'access')]
    public string $tab = 'access';

    /** Отчётный месяц (первый день) в формате Y-m-d — общий для плана, журнала и отчёта. */
    #[Url(as: 'm', except: '')]
    public string $period = '';

    public ?string $flashMessage = null;

    public ?string $flashError = null;

    /* ---------------------------- Доступы ---------------------------- */

    public bool $showServiceForm = false;

    public ?int $serviceEditId = null;

    public string $sName = '';

    public string $sCategory = 'ads';

    public string $sUrl = '';

    public string $sLogin = '';

    public string $sPassword = '';

    public string $sApiKey = '';

    public string $sExtra = '';

    public string $sOwner = '';

    public string $sNotes = '';

    public bool $sActive = true;

    /** id сервисов, для которых секреты сейчас раскрыты на экране. */
    public array $revealed = [];

    /* ------------------------ Записная книжка ------------------------ */

    public bool $showContactForm = false;

    public ?int $contactEditId = null;

    #[Url(as: 'q', except: '')]
    public string $contactSearch = '';

    public string $cTopic = '';

    public string $cOrganization = '';

    public string $cPerson = '';

    /** Адреса в форме — по одному на строку или через запятую. */
    public string $cEmails = '';

    public string $cPhone = '';

    public string $cFolder = '';

    public string $cNotes = '';

    public bool $cActive = true;

    /* ------------------------ План / журнал ------------------------ */

    public bool $showEntryForm = false;

    public ?int $entryEditId = null;

    public string $eKind = MarketingEntry::KIND_WORK;

    public string $eSection = 'ads';

    public string $eTitle = '';

    public string $eBody = '';

    public string $eStatus = MarketingEntry::STATUS_DONE;

    public int $ePriority = 2;

    public string $eHappenedOn = '';

    /** Показатели рекламы — только для раздела «Реклама». */
    public array $eMetrics = ['spend' => '', 'impressions' => '', 'clicks' => '', 'leads' => '', 'other' => ''];

    /* ---------------------------- Отчёт ---------------------------- */

    /** Редактируемая форма отчёта: sections[section][field], main_tasks[], next_plan[], ad_metrics[]. */
    public array $form = [];

    public array $requisites = ['contract_number' => '', 'contract_date' => '', 'contractor' => '', 'customer' => ''];

    public function mount(): void
    {
        $this->ensureAdmin();
        if ($this->period === '') {
            $this->period = now()->startOfMonth()->toDateString();
        }
        $this->eHappenedOn = now()->toDateString();
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'access';
        }
        // Вкладку отчёта можно открыть прямой ссылкой (?tab=report) — форму
        // надо собрать сразу, иначе поля будут пустыми до первого клика.
        if ($this->tab === 'report') {
            $this->loadReport();
        }
    }

    /* ------------------------- Медиапрофиль -------------------------- */

    /** Открытая форма записи медиапрофиля: null — форма закрыта. */
    public ?string $profileFacet = null;

    public ?int $profileEditId = null;

    public string $pStatement = '';

    public string $pDetails = '';

    public bool $pStrict = false;

    /**
     * Записи профиля по граням.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\MediaProfileEntry>>
     */
    #[Computed]
    public function profileEntries()
    {
        return \App\Models\MediaProfileEntry::query()
            ->with('author:id,name')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($e) => $e->facet->value);
    }

    public function startProfileEntry(string $facet): void
    {
        $this->profileFacet = \App\Enums\MediaProfileFacet::tryFrom($facet)?->value;
        $this->profileEditId = null;
        $this->pStatement = '';
        $this->pDetails = '';
        $this->pStrict = false;
    }

    public function editProfileEntry(int $id): void
    {
        $entry = \App\Models\MediaProfileEntry::find($id);
        if ($entry === null) {
            return;
        }

        $this->profileFacet = $entry->facet->value;
        $this->profileEditId = $entry->id;
        $this->pStatement = (string) $entry->statement;
        $this->pDetails = (string) $entry->details;
        $this->pStrict = (bool) $entry->is_strict;
    }

    public function cancelProfileEntry(): void
    {
        $this->profileFacet = null;
        $this->profileEditId = null;
    }

    public function saveProfileEntry(): void
    {
        $facet = \App\Enums\MediaProfileFacet::tryFrom((string) $this->profileFacet);
        $statement = trim($this->pStatement);

        if ($facet === null || $statement === '') {
            $this->flashError = 'Нужна рубрика и само утверждение.';

            return;
        }

        \App\Models\MediaProfileEntry::updateOrCreate(
            ['id' => $this->profileEditId],
            [
                'facet' => $facet->value,
                'statement' => mb_substr($statement, 0, 500),
                'details' => trim($this->pDetails) !== '' ? trim($this->pDetails) : null,
                'is_strict' => $this->pStrict,
                'is_active' => true,
                'created_by_user_id' => $this->profileEditId ? null : auth()->id(),
            ] + ($this->profileEditId ? [] : ['position' => 0]),
        );

        $this->flashMessage = $this->profileEditId ? 'Запись обновлена.' : 'Запись добавлена в медиапрофиль.';
        $this->cancelProfileEntry();
        unset($this->profileEntries);
    }

    /** Убрать из профиля: запись перестаёт участвовать в проверке материалов. */
    public function toggleProfileEntry(int $id): void
    {
        $entry = \App\Models\MediaProfileEntry::find($id);
        if ($entry === null) {
            return;
        }

        $entry->forceFill(['is_active' => ! $entry->is_active])->save();
        unset($this->profileEntries);
    }

    public function deleteProfileEntry(int $id): void
    {
        \App\Models\MediaProfileEntry::where('id', $id)->delete();
        $this->flashMessage = 'Запись удалена.';
        unset($this->profileEntries);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'access';
        $this->flashMessage = null;
        $this->flashError = null;
        if ($this->tab === 'report') {
            $this->loadReport();
        }
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = $this->periodDate()->addMonths($months)->toDateString();
        if ($this->tab === 'report') {
            $this->loadReport();
        }
    }

    public function periodDate(): Carbon
    {
        return MarketingEntry::normalizePeriod($this->period ?: now());
    }

    public function periodLabel(): string
    {
        return MarketingReport::monthLabel($this->periodDate());
    }

    /* =========================== Доступы =========================== */

    #[Computed]
    public function services()
    {
        return MarketingService::query()
            ->orderByDesc('is_active')
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    public function startServiceCreate(): void
    {
        $this->resetServiceForm();
        $this->showServiceForm = true;
    }

    public function startServiceEdit(int $id): void
    {
        $service = MarketingService::query()->find($id);
        if ($service === null) {
            return;
        }
        $secrets = $service->secrets();
        $this->serviceEditId = $service->id;
        $this->sName = (string) $service->name;
        $this->sCategory = (string) $service->category;
        $this->sUrl = (string) $service->url;
        $this->sLogin = (string) $service->login;
        $this->sPassword = (string) ($secrets['password'] ?? '');
        $this->sApiKey = (string) ($secrets['api_key'] ?? '');
        $this->sExtra = (string) ($secrets['extra'] ?? '');
        $this->sOwner = (string) $service->account_owner;
        $this->sNotes = (string) $service->notes;
        $this->sActive = (bool) $service->is_active;
        $this->showServiceForm = true;
    }

    public function saveService(): void
    {
        $this->ensureAdmin();
        $this->validate([
            'sName' => ['required', 'string', 'max:120'],
            'sCategory' => ['required', 'string', 'in:'.implode(',', array_keys(MarketingService::CATEGORIES))],
            'sUrl' => ['nullable', 'string', 'max:500'],
            'sLogin' => ['nullable', 'string', 'max:255'],
            'sOwner' => ['nullable', 'string', 'max:160'],
        ], [], [
            'sName' => 'название', 'sCategory' => 'категория', 'sUrl' => 'адрес', 'sLogin' => 'логин',
        ]);

        $service = $this->serviceEditId ? MarketingService::query()->find($this->serviceEditId) : new MarketingService;
        if ($service === null) {
            $this->flashError = 'Сервис не найден — возможно, его удалили.';

            return;
        }
        $isNew = ! $service->exists;

        $service->fill([
            'name' => trim($this->sName),
            'category' => $this->sCategory,
            'url' => trim($this->sUrl) ?: null,
            'login' => trim($this->sLogin) ?: null,
            'account_owner' => trim($this->sOwner) ?: null,
            'notes' => trim($this->sNotes) ?: null,
            'is_active' => $this->sActive,
            'updated_by_user_id' => Auth::id(),
        ]);
        if ($isNew) {
            $service->created_by_user_id = Auth::id();
        }
        $service->writeSecrets([
            'password' => $this->sPassword,
            'api_key' => $this->sApiKey,
            'extra' => $this->sExtra,
        ]);
        $service->save();

        $this->resetServiceForm();
        unset($this->services);
        $this->flashMessage = $isNew ? 'Доступ добавлен.' : 'Доступ обновлён.';
    }

    public function toggleReveal(int $id): void
    {
        $this->ensureAdmin();
        $this->revealed = in_array($id, $this->revealed, true)
            ? array_values(array_diff($this->revealed, [$id]))
            : [...$this->revealed, $id];
    }

    /** Секреты сервиса для показа — только когда админ раскрыл карточку. */
    public function revealedSecrets(int $id): array
    {
        if (! in_array($id, $this->revealed, true)) {
            return [];
        }

        return MarketingService::query()->find($id)?->secrets() ?? [];
    }

    public function markVerified(int $id): void
    {
        $this->ensureAdmin();
        MarketingService::query()->whereKey($id)->update([
            'last_verified_at' => now(),
            'updated_by_user_id' => Auth::id(),
        ]);
        unset($this->services);
        $this->flashMessage = 'Отмечено: доступ проверен.';
    }

    public function deleteService(int $id): void
    {
        $this->ensureAdmin();
        MarketingService::query()->whereKey($id)->delete();
        $this->revealed = array_values(array_diff($this->revealed, [$id]));
        unset($this->services);
        $this->flashMessage = 'Доступ удалён.';
    }

    public function cancelServiceForm(): void
    {
        $this->resetServiceForm();
    }

    private function resetServiceForm(): void
    {
        $this->reset(['serviceEditId', 'sName', 'sUrl', 'sLogin', 'sPassword', 'sApiKey', 'sExtra', 'sOwner', 'sNotes']);
        $this->sCategory = 'ads';
        $this->sActive = true;
        $this->showServiceForm = false;
        $this->resetValidation();
    }

    /* ===================== Записная книжка ===================== */

    /**
     * Контакты, сгруппированные по направлению: в книжке ищут «кто у нас по
     * календарям», а не отдельную строку.
     *
     * @return Collection<string, Collection<int, MarketingContact>>
     */
    #[Computed]
    public function contactGroups()
    {
        return MarketingContact::query()
            ->search($this->contactSearch)
            ->orderBy('topic')
            ->orderByDesc('is_active')
            ->orderBy('organization')
            ->get()
            ->groupBy('topic');
    }

    /** Направления для подсказки в форме. @return array<int, string> */
    #[Computed]
    public function contactTopics(): array
    {
        return MarketingContact::query()->distinct()->orderBy('topic')->pluck('topic')
            ->map(fn ($t) => (string) $t)->all();
    }

    #[Computed]
    public function contactsTotal(): int
    {
        return MarketingContact::query()->count();
    }

    public function startContactCreate(): void
    {
        $this->resetContactForm();
        $this->showContactForm = true;
    }

    public function startContactEdit(int $id): void
    {
        $contact = MarketingContact::query()->find($id);
        if ($contact === null) {
            return;
        }
        $this->contactEditId = $contact->id;
        $this->cTopic = (string) $contact->topic;
        $this->cOrganization = (string) $contact->organization;
        $this->cPerson = (string) $contact->contact_person;
        $this->cEmails = $contact->emailsText();
        $this->cPhone = (string) $contact->phone;
        $this->cFolder = (string) $contact->folder_path;
        $this->cNotes = (string) $contact->notes;
        $this->cActive = (bool) $contact->is_active;
        $this->showContactForm = true;
    }

    public function saveContact(): void
    {
        $this->ensureAdmin();
        $this->validate([
            'cTopic' => ['required', 'string', 'max:80'],
            'cOrganization' => ['required', 'string', 'max:200'],
            'cPerson' => ['nullable', 'string', 'max:160'],
            'cPhone' => ['nullable', 'string', 'max:120'],
            'cFolder' => ['nullable', 'string', 'max:500'],
        ], [], ['cTopic' => 'направление', 'cOrganization' => 'организация']);

        $contact = $this->contactEditId ? MarketingContact::query()->find($this->contactEditId) : new MarketingContact;
        if ($contact === null) {
            $this->flashError = 'Контакт не найден.';

            return;
        }
        $isNew = ! $contact->exists;

        $emails = MarketingContact::parseEmails($this->cEmails);
        $contact->fill([
            'topic' => trim($this->cTopic),
            'organization' => trim($this->cOrganization),
            'contact_person' => trim($this->cPerson) ?: null,
            'emails' => $emails ?: null,
            'phone' => trim($this->cPhone) ?: null,
            'folder_path' => trim($this->cFolder) ?: null,
            'notes' => trim($this->cNotes) ?: null,
            'is_active' => $this->cActive,
            'updated_by_user_id' => Auth::id(),
        ]);
        if ($isNew) {
            $contact->created_by_user_id = Auth::id();
        }
        $contact->save();

        $this->resetContactForm();
        unset($this->contactGroups, $this->contactTopics, $this->contactsTotal);
        $this->flashMessage = $isNew ? 'Контакт добавлен.' : 'Контакт обновлён.';
    }

    public function deleteContact(int $id): void
    {
        $this->ensureAdmin();
        MarketingContact::query()->whereKey($id)->delete();
        unset($this->contactGroups, $this->contactTopics, $this->contactsTotal);
        $this->flashMessage = 'Контакт удалён.';
    }

    public function cancelContactForm(): void
    {
        $this->resetContactForm();
    }

    private function resetContactForm(): void
    {
        $this->reset(['contactEditId', 'cTopic', 'cOrganization', 'cPerson', 'cEmails', 'cPhone', 'cFolder', 'cNotes']);
        $this->cActive = true;
        $this->showContactForm = false;
        $this->resetValidation();
    }

    /* ======================== План и журнал ======================== */

    #[Computed]
    public function planEntries()
    {
        return MarketingEntry::query()
            ->forPeriod($this->periodDate())
            ->whereIn('kind', [MarketingEntry::KIND_PLAN, MarketingEntry::KIND_NOTE])
            ->orderBy('priority')->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function logEntries()
    {
        return MarketingEntry::query()
            ->forPeriod($this->periodDate())
            ->ofKind(MarketingEntry::KIND_WORK)
            ->orderByDesc('happened_on')->orderByDesc('id')
            ->get();
    }

    /** Сводка месяца для шапки: работ, план, сделано из плана. */
    #[Computed]
    public function periodSummary(): array
    {
        $rows = MarketingEntry::query()->forPeriod($this->periodDate())->get();

        return [
            'work' => $rows->where('kind', MarketingEntry::KIND_WORK)->count(),
            'plan' => $rows->where('kind', MarketingEntry::KIND_PLAN)->count(),
            'plan_done' => $rows->where('kind', MarketingEntry::KIND_PLAN)
                ->where('status', MarketingEntry::STATUS_DONE)->count(),
            'note' => $rows->where('kind', MarketingEntry::KIND_NOTE)->count(),
        ];
    }

    public function startEntryCreate(string $kind): void
    {
        $this->resetEntryForm();
        $this->eKind = in_array($kind, array_keys(MarketingEntry::KINDS), true) ? $kind : MarketingEntry::KIND_WORK;
        $this->eStatus = $this->eKind === MarketingEntry::KIND_WORK
            ? MarketingEntry::STATUS_DONE
            : MarketingEntry::STATUS_PLANNED;
        $this->showEntryForm = true;
    }

    public function startEntryEdit(int $id): void
    {
        $entry = MarketingEntry::query()->find($id);
        if ($entry === null) {
            return;
        }
        $this->entryEditId = $entry->id;
        $this->eKind = (string) $entry->kind;
        $this->eSection = (string) $entry->section;
        $this->eTitle = (string) $entry->title;
        $this->eBody = (string) $entry->body;
        $this->eStatus = (string) $entry->status;
        $this->ePriority = (int) $entry->priority;
        $this->eHappenedOn = $entry->happened_on?->toDateString() ?? '';
        $this->eMetrics = array_merge(
            ['spend' => '', 'impressions' => '', 'clicks' => '', 'leads' => '', 'other' => ''],
            array_map(fn ($v) => (string) $v, (array) $entry->metrics)
        );
        $this->showEntryForm = true;
    }

    public function saveEntry(): void
    {
        $this->ensureAdmin();
        $this->validate([
            'eTitle' => ['required', 'string', 'max:200'],
            'eKind' => ['required', 'in:'.implode(',', array_keys(MarketingEntry::KINDS))],
            'eSection' => ['required', 'in:'.implode(',', array_column(MarketingSection::cases(), 'value'))],
            'eStatus' => ['required', 'in:'.implode(',', array_keys(MarketingEntry::STATUSES))],
            'ePriority' => ['required', 'integer', 'between:1,3'],
            'eHappenedOn' => ['nullable', 'date'],
        ], [], ['eTitle' => 'название']);

        $entry = $this->entryEditId ? MarketingEntry::query()->find($this->entryEditId) : new MarketingEntry;
        if ($entry === null) {
            $this->flashError = 'Запись не найдена.';

            return;
        }
        $isNew = ! $entry->exists;

        $metrics = [];
        if ($this->eSection === MarketingSection::Ads->value) {
            foreach ($this->eMetrics as $k => $v) {
                if (trim((string) $v) !== '') {
                    $metrics[$k] = trim((string) $v);
                }
            }
        }

        $entry->fill([
            'kind' => $this->eKind,
            'section' => $this->eSection,
            'period' => $this->periodDate(),
            'title' => trim($this->eTitle),
            'body' => trim($this->eBody) ?: null,
            'metrics' => $metrics ?: null,
            'status' => $this->eStatus,
            'priority' => $this->ePriority,
            'happened_on' => $this->eKind === MarketingEntry::KIND_WORK
                ? ($this->eHappenedOn ?: $this->periodDate()->toDateString())
                : ($this->eHappenedOn ?: null),
        ]);
        if ($isNew) {
            $entry->created_by_user_id = Auth::id();
        }
        $entry->save();

        $this->resetEntryForm();
        unset($this->planEntries, $this->logEntries, $this->periodSummary);
        $this->flashMessage = $isNew ? 'Запись добавлена.' : 'Запись обновлена.';
    }

    public function setEntryStatus(int $id, string $status): void
    {
        $this->ensureAdmin();
        if (! array_key_exists($status, MarketingEntry::STATUSES)) {
            return;
        }
        MarketingEntry::query()->whereKey($id)->update(['status' => $status]);
        unset($this->planEntries, $this->logEntries, $this->periodSummary);
    }

    /** Перенести пункт плана в следующий месяц (не успели — не теряем). */
    public function movePlanToNextMonth(int $id): void
    {
        $this->ensureAdmin();
        $entry = MarketingEntry::query()->find($id);
        if ($entry === null) {
            return;
        }
        $entry->period = MarketingEntry::normalizePeriod($entry->period)->addMonth();
        $entry->save();
        unset($this->planEntries, $this->periodSummary);
        $this->flashMessage = 'Пункт перенесён на '.MarketingReport::monthLabel($entry->period).'.';
    }

    public function deleteEntry(int $id): void
    {
        $this->ensureAdmin();
        MarketingEntry::query()->whereKey($id)->delete();
        unset($this->planEntries, $this->logEntries, $this->periodSummary);
        $this->flashMessage = 'Запись удалена.';
    }

    public function cancelEntryForm(): void
    {
        $this->resetEntryForm();
    }

    private function resetEntryForm(): void
    {
        $this->reset(['entryEditId', 'eTitle', 'eBody']);
        $this->eSection = 'ads';
        $this->ePriority = 2;
        $this->eHappenedOn = now()->toDateString();
        $this->eMetrics = ['spend' => '', 'impressions' => '', 'clicks' => '', 'leads' => '', 'other' => ''];
        $this->showEntryForm = false;
        $this->resetValidation();
    }

    /* ============================ Отчёт ============================ */

    #[Computed]
    public function report(): ?MarketingReport
    {
        return MarketingReport::query()->whereDate('period', $this->periodDate()->toDateString())->first();
    }

    /** Загрузить форму отчёта: сохранённое поверх пересобранного из журнала. */
    public function loadReport(): void
    {
        $svc = app(MarketingReportService::class);
        $report = $this->report;
        $this->form = $report !== null ? $svc->mergeWithSaved($report) : $svc->buildDraft($this->periodDate());
        $this->requisites = array_merge($this->requisites, $svc->requisites());
        $this->normalizeFormArrays();
    }

    /** Пересобрать текст разделов из журнала, затерев ручные правки. */
    public function rebuildReport(): void
    {
        $this->ensureAdmin();
        $this->form = app(MarketingReportService::class)->buildDraft($this->periodDate());
        $this->normalizeFormArrays();
        $this->flashMessage = 'Форма пересобрана из журнала. Ручные правки разделов затёрты.';
    }

    public function saveReport(): void
    {
        $this->ensureAdmin();
        // Сбрасываем прошлую ошибку: finalizeReport() ориентируется на неё,
        // чтобы не отмечать сданным отчёт, который не сохранился.
        $this->flashMessage = null;
        $this->flashError = null;
        $svc = app(MarketingReportService::class);
        $svc->saveRequisites($this->requisites, Auth::user());

        $report = $svc->draftFor($this->periodDate(), Auth::user());
        if ($report->isFinal()) {
            $this->flashError = 'Отчёт уже сдан — снимите отметку, чтобы править.';

            return;
        }
        $payload = $this->form;
        $payload['period'] = $this->periodDate()->toDateString();
        $payload['requisites'] = $this->requisites;
        $payload['main_tasks'] = $this->cleanList($payload['main_tasks'] ?? []);
        $payload['next_plan'] = $this->cleanList($payload['next_plan'] ?? []);
        $report->payload = $payload;
        $report->save();

        unset($this->report);
        $this->flashMessage = 'Отчёт сохранён.';
    }

    public function finalizeReport(): void
    {
        $this->ensureAdmin();
        $this->saveReport();
        $report = $this->report;
        if ($report === null || $this->flashError !== null) {
            return;
        }
        $report->status = MarketingReport::STATUS_FINAL;
        $report->finalized_at = now();
        $report->save();
        unset($this->report);
        $this->flashMessage = 'Отчёт за '.$this->periodLabel().' отмечен как сданный.';
    }

    public function reopenReport(): void
    {
        $this->ensureAdmin();
        $report = $this->report;
        if ($report === null) {
            return;
        }
        $report->status = MarketingReport::STATUS_DRAFT;
        $report->finalized_at = null;
        $report->save();
        unset($this->report);
        $this->flashMessage = 'Отчёт снова редактируется.';
    }

    /** Ровно пять строк в списках задач/плана — как в форме Приложения № 1. */
    private function normalizeFormArrays(): void
    {
        foreach (['main_tasks', 'next_plan'] as $key) {
            $list = array_values(array_map(fn ($v) => (string) $v, (array) ($this->form[$key] ?? [])));
            $this->form[$key] = array_pad(array_slice($list, 0, 5), 5, '');
        }
        $this->form['ad_metrics'] = array_merge(
            array_fill_keys(array_keys(MarketingSection::AD_METRICS), ''),
            array_map(fn ($v) => (string) $v, (array) ($this->form['ad_metrics'] ?? []))
        );
        foreach (MarketingSection::ordered() as $section) {
            foreach (array_keys($section->fields()) as $field) {
                $this->form['sections'][$section->value][$field] =
                    (string) ($this->form['sections'][$section->value][$field] ?? '');
            }
        }
    }

    /** @return array<int, string> */
    private function cleanList(array $list): array
    {
        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $list), fn ($v) => $v !== ''));
    }

    /* =========================================================== */

    public function render()
    {
        return view('livewire.marketing.workspace', [
            'sections' => MarketingSection::ordered(),
            'categories' => MarketingService::CATEGORIES,
        ]);
    }

    private function ensureAdmin(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->hasRole('admin')) {
            abort(403);
        }
    }
}
