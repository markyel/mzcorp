<?php

namespace App\Livewire\Catalog;

use App\Enums\Role;
use App\Models\Identification\IdentificationHint;
use App\Models\Identification\IdentificationPartType;
use App\Services\Catalog\IdentificationHintService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Справочник опознания» (РОП, директорат): какие вопросы задать клиенту, чтобы опознать деталь, по типам
 * деталей. Пополняется сам по письмам-уточнениям менеджеров (App\Services\Catalog\IdentificationLearner):
 * вопрос из 3 писем становится подсказкой, новый тип детали ждёт подтверждения здесь.
 */
class IdentificationHints extends Component
{
    /** all | candidates (ждут решения) | disabled */
    #[Url(as: 'f')]
    public string $filter = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    /** Название позиции для проверки: какой тип узнается. */
    public string $probe = '';

    public ?int $editTypeId = null;

    public string $typeName = '';

    public string $typePattern = '';

    public ?int $editHintId = null;

    public string $hintLabel = '';

    public string $hintQuestion = '';

    /** Какие данные вопрос должен дать — по ней проверяется, есть ли ответ у позиции. */
    public string $hintGoal = '';

    /** Тип, в который добавляем вопрос. */
    public ?int $addHintTypeId = null;

    public bool $addingType = false;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    #[Computed]
    public function types(): Collection
    {
        $q = mb_strtolower(trim($this->search));

        return IdentificationPartType::query()->orderByDesc(DB::raw('(not is_active)::int'))->orderBy('sort')->orderBy('id')
            ->with(['hints' => fn ($h) => $h->orderByRaw("case status when 'candidate' then 0 when 'active' then 1 else 2 end")->orderByDesc('asked_count')->orderBy('sort')->orderBy('id')])
            ->get()
            ->filter(function (IdentificationPartType $t) use ($q) {
                if ($q !== '' && ! str_contains(mb_strtolower($t->name.' '.$t->key.' '.$t->hints->pluck('label')->implode(' ')), $q)) {
                    return false;
                }

                return match ($this->filter) {
                    'candidates' => ! $t->is_active || $t->hints->contains('status', IdentificationHint::CANDIDATE),
                    'disabled' => $t->hints->contains('status', IdentificationHint::DISABLED),
                    default => true,
                };
            })->values();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'types' => IdentificationPartType::query()->where('is_active', true)->count(),
            'hints' => IdentificationHint::query()->where('status', IdentificationHint::ACTIVE)->count(),
            'waiting' => IdentificationHint::query()->where('status', IdentificationHint::CANDIDATE)->count()
                + IdentificationPartType::query()->where('is_active', false)->count(),
            'letters' => DB::table('identification_observations')->distinct()->count('email_message_id'),
        ];
    }

    /** Последние формулировки менеджеров по вопросу — понять, откуда он взялся. */
    #[Computed]
    public function examples(): array
    {
        $ids = $this->types->flatMap(fn ($t) => $t->hints->pluck('id'))->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('identification_observations as o')->leftJoin('requests as r', 'r.id', '=', 'o.request_id')
            ->whereIn('o.hint_id', $ids)->whereNotNull('o.question')
            ->orderByDesc('o.id')->get(['o.hint_id', 'o.question', 'o.request_id', 'r.internal_code'])
            ->groupBy('hint_id')->map(fn ($g) => $g->take(3)->values()->all())->all();
    }

    #[Computed]
    public function probeResult(): ?array
    {
        if (trim($this->probe) === '') {
            return null;
        }
        $all = IdentificationPartType::query()->get()->map(fn ($t) => ['key' => $t->key, 'name' => $t->name.($t->is_active ? '' : ' (не подтверждён)'), 'pattern' => $t->pattern, 'hints' => []])->all();

        return IdentificationHintService::typeOf($this->probe, $all) ?? ['key' => '', 'name' => '', 'hints' => []];
    }

    public function setHintStatus(int $id, string $status): void
    {
        $this->authorizeAccess();
        if (! in_array($status, [IdentificationHint::ACTIVE, IdentificationHint::CANDIDATE, IdentificationHint::DISABLED], true)) {
            return;
        }
        IdentificationHint::query()->whereKey($id)->update(['status' => $status]);
        $this->changed();
    }

    public function toggleType(int $id): void
    {
        $this->authorizeAccess();
        $type = IdentificationPartType::query()->findOrFail($id);
        $type->update(['is_active' => ! $type->is_active]);
        $this->changed();
    }

    public function startEditType(int $id): void
    {
        $type = IdentificationPartType::query()->findOrFail($id);
        $this->resetForms();
        $this->editTypeId = $type->id;
        $this->typeName = $type->name;
        $this->typePattern = (string) $type->pattern;
    }

    public function startAddType(): void
    {
        $this->resetForms();
        $this->addingType = true;
    }

    public function saveType(): void
    {
        $this->authorizeAccess();
        $this->validate([
            'typeName' => 'required|string|max:120',
            'typePattern' => 'required|string|max:2000',
        ], [], ['typeName' => 'название', 'typePattern' => 'правило']);
        $pattern = self::pattern($this->typePattern);
        if ($pattern === null) {
            $this->addError('typePattern', 'Правило не разобрать: слова через запятую или регулярное выражение вида /…/iu.');

            return;
        }
        if ($this->editTypeId !== null) {
            IdentificationPartType::query()->whereKey($this->editTypeId)->update(['name' => trim($this->typeName), 'pattern' => $pattern]);
        } else {
            $key = 'manual_'.Str::slug(Str::transliterate($this->typeName), '_').'_'.Str::lower(Str::random(4));
            $type = IdentificationPartType::create(['key' => $key, 'name' => trim($this->typeName), 'pattern' => $pattern, 'sort' => 800, 'is_active' => true, 'source' => 'manual']);
            $this->addHintTypeId = $type->id;
        }
        $this->resetForms(keepAddHint: true);
        $this->changed();
    }

    public function startEditHint(int $id): void
    {
        $hint = IdentificationHint::query()->findOrFail($id);
        $this->resetForms();
        $this->editHintId = $hint->id;
        $this->hintLabel = $hint->label;
        $this->hintQuestion = $hint->question;
        $this->hintGoal = (string) $hint->goal;
    }

    public function startAddHint(int $typeId): void
    {
        $this->resetForms();
        $this->addHintTypeId = $typeId;
    }

    public function saveHint(): void
    {
        $this->authorizeAccess();
        $this->validate([
            'hintLabel' => 'required|string|max:120',
            'hintQuestion' => 'required|string|max:1000',
            'hintGoal' => 'nullable|string|max:300',
        ], [], ['hintLabel' => 'название', 'hintQuestion' => 'вопрос клиенту', 'hintGoal' => 'цель']);
        $goal = trim($this->hintGoal) ?: null;
        if ($this->editHintId !== null) {
            IdentificationHint::query()->whereKey($this->editHintId)->update(['label' => trim($this->hintLabel), 'question' => trim($this->hintQuestion), 'goal' => $goal]);
        } elseif ($this->addHintTypeId !== null && IdentificationPartType::query()->whereKey($this->addHintTypeId)->exists()) {
            IdentificationHint::create([
                'part_type_id' => $this->addHintTypeId, 'label' => trim($this->hintLabel), 'question' => trim($this->hintQuestion), 'goal' => $goal,
                'status' => IdentificationHint::ACTIVE, 'source' => 'manual', 'sort' => 50,
            ]);
        }
        $this->resetForms();
        $this->changed();
    }

    public function cancel(): void
    {
        $this->resetForms();
    }

    /**
     * Правило распознавания: регулярное выражение как есть или слова через запятую — начало слова
     * («шкив, блок» → /(?<!\p{L})(?:шкив|блок)/iu). Не разбирается — null.
     */
    public static function pattern(string $input): ?string
    {
        $input = trim($input);
        if (! str_starts_with($input, '/')) {
            $words = array_values(array_filter(array_map(fn ($w) => preg_quote(mb_strtolower(trim($w)), '/'), explode(',', $input)), fn ($w) => $w !== ''));
            if ($words === []) {
                return null;
            }
            $input = '/(?<!\p{L})(?:'.implode('|', $words).')/iu';
        }

        return @preg_match($input, '') === false ? null : $input;
    }

    private function resetForms(bool $keepAddHint = false): void
    {
        $this->resetErrorBag();
        $this->editTypeId = $this->editHintId = null;
        $this->addingType = false;
        $this->typeName = $this->typePattern = $this->hintLabel = $this->hintQuestion = $this->hintGoal = '';
        if (! $keepAddHint) {
            $this->addHintTypeId = null;
        }
    }

    private function changed(): void
    {
        IdentificationHintService::forget();
        unset($this->types, $this->counts, $this->examples, $this->probeResult);
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value]), 403);
    }

    public function render()
    {
        return view('livewire.catalog.identification-hints');
    }
}
