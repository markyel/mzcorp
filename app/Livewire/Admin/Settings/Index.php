<?php

namespace App\Livewire\Admin\Settings;

use App\Models\AppSetting;
use App\Services\Settings\SettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Phase 2 шаг 2 UI «Настройки»: редактирование значений `app_settings`
 * с fallback на config/env, если override отсутствует.
 *
 * Доступ — middleware role:head_of_sales,director (см. routes/web.php).
 *
 * Каждая редактируемая настройка описана в SCHEMA — тип, дефолт из config,
 * подпись/подсказка. UI рендерит соответствующий control:
 *   - bool   → checkbox
 *   - float  → input type=number step=0.01
 *   - int    → input type=number step=1
 *   - string → input type=text (или select, если есть `options`)
 *
 * Save кнопка пишет ВСЕ изменённые поля одной партией через
 * SettingsService и сбрасывает кэш — изменения видны на следующем
 * запросе (без queue:restart / config:clear).
 */
class Index extends Component
{
    /**
     * Текущие значения формы. Ключ — dot-нотация настройки, значение —
     * введённое пользователем (приведённое к типу при сохранении).
     */
    public array $values = [];

    /**
     * Schema editable-настроек.
     *
     * @return array<string, array{
     *   group: string,
     *   label: string,
     *   help: string,
     *   type: 'string'|'int'|'float'|'bool',
     *   default: mixed,
     *   step?: float,
     *   min?: float|int,
     *   max?: float|int,
     *   options?: array<string, string>,
     * }>
     */
    public static function schema(): array
    {
        return [
            // ─── Каталог: matching по name ────────────────────────────────
            'catalog.name_match.enabled' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'Включить семантический матчинг (C-step)',
                'help' => 'Если выключено — позиции без точного M-SKU или brand_article не сматчиваются с каталогом через эмбеддинги.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => true,
            ],
            'catalog.name_match.threshold' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'Порог cosine similarity',
                'help' => 'Минимальное сходство (0..1) для попадания в C-step. Ниже — vector top-1 отклоняется.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => 0.75,
                'step' => 0.01,
                'min' => 0.0,
                'max' => 1.0,
            ],
            'catalog.name_match.hc_threshold' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'High-confidence порог (без LLM-проверки)',
                'help' => 'Similarity ≥ этого значения → LLM-валидация пропускается, vector считается достоверным. Между threshold и hc — обязательная LLM-проверка.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => 0.90,
                'step' => 0.01,
                'min' => 0.0,
                'max' => 1.0,
            ],
            'catalog.name_match.llm_validation_enabled' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'Включить LLM-валидацию',
                'help' => 'Третий stage two-stage retrieval: gpt-4o-mini проверяет, действительно ли это один и тот же товар. Без неё precision падает.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => true,
            ],
            'catalog.name_match.llm_fail_action' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'Поведение при сбое LLM',
                'help' => '«reject» — match отклоняем (precision приоритет). «accept» — принимаем без проверки (recall приоритет).',
                'type' => AppSetting::TYPE_STRING,
                'default' => 'reject',
                'options' => ['reject' => 'reject (отклонять)', 'accept' => 'accept (принимать)'],
            ],

            // ─── Каталог: импорт ──────────────────────────────────────────
            'catalog.import.min_full_rows' => [
                'group' => 'Каталог · импорт',
                'label' => 'Минимум строк в full snapshot',
                'help' => 'POST /api/catalog/import с rows < этого значения отвергается 422 без записи в БД. Защита от случайного обнуления каталога.',
                'type' => AppSetting::TYPE_INT,
                'default' => 1,
                'step' => 100,
                'min' => 1,
            ],

            // ─── Налоги ───────────────────────────────────────────────────
            'tax.vat_percent' => [
                'group' => 'Налоги',
                'label' => 'Ставка НДС, %',
                'help' => 'Используется в hero и table-footer карточки заявки для расчёта итога. 2026+: 22. Поддерживает дробные значения.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => 22,
                'step' => 0.5,
                'min' => 0,
                'max' => 50,
            ],
        ];
    }

    public function mount(SettingsService $svc): void
    {
        foreach (self::schema() as $key => $meta) {
            // Текущее значение = app_setting() (DB-override → fallback config()).
            $current = $svc->get($key, config($this->configKeyFor($key), $meta['default']));
            // Для bool — храним как настоящий bool, для остальных — string-like
            // (Livewire так передаёт в HTML-input).
            $this->values[$key] = $meta['type'] === AppSetting::TYPE_BOOL
                ? (bool) $current
                : ($current === null ? '' : (string) $current);
        }
    }

    /**
     * Параллельный config-ключ для дефолта. Большинство наших настроек
     * мапятся в `services.<...>` — берём этот префикс.
     */
    private function configKeyFor(string $key): string
    {
        // catalog.name_match.threshold → services.catalog_name_match.threshold
        // catalog.import.min_full_rows → services.catalog_import.min_full_rows
        // tax.vat_percent              → services.tax.vat_percent
        $configMap = [
            'catalog.name_match.enabled' => 'services.catalog_name_match.enabled',
            'catalog.name_match.threshold' => 'services.catalog_name_match.threshold',
            'catalog.name_match.hc_threshold' => 'services.catalog_name_match.hc_threshold',
            'catalog.name_match.llm_validation_enabled' => 'services.catalog_name_match.llm_validation_enabled',
            'catalog.name_match.llm_fail_action' => 'services.catalog_name_match.llm_fail_action',
            'catalog.import.min_full_rows' => 'services.catalog_import.min_full_rows',
            'tax.vat_percent' => 'services.tax.vat_percent',
        ];

        return $configMap[$key] ?? '';
    }

    /**
     * @return array<string, array<string, array>>  group → [key → meta]
     */
    #[Computed]
    public function grouped(): array
    {
        $out = [];
        foreach (self::schema() as $key => $meta) {
            $out[$meta['group']][$key] = $meta;
        }

        return $out;
    }

    public function save(SettingsService $svc): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasAnyRole(['head_of_sales', 'director'])) {
            abort(403);
        }

        $schema = self::schema();
        foreach ($this->values as $key => $rawValue) {
            if (! isset($schema[$key])) {
                continue;
            }
            $meta = $schema[$key];
            $typed = $this->coerceForType($rawValue, $meta['type']);

            $configDefault = config($this->configKeyFor($key), $meta['default']);
            $defaultTyped = $this->coerceForType($configDefault, $meta['type']);

            // Если значение совпадает с config-defaults — удаляем override
            // (чтобы не плодить мусор и legko вернуться к defaults).
            if ($this->valuesEqual($typed, $defaultTyped, $meta['type'])) {
                $svc->unset($key);
                continue;
            }

            $svc->set($key, $typed, $meta['type'], $user->id, $meta['label']);
        }

        $this->dispatch('settings-saved');
        session()->flash('settings-flash', 'Настройки сохранены.');
    }

    private function coerceForType(mixed $raw, string $type): mixed
    {
        if ($type === AppSetting::TYPE_BOOL) {
            return (bool) $raw;
        }
        if ($type === AppSetting::TYPE_INT) {
            return (int) $raw;
        }
        if ($type === AppSetting::TYPE_FLOAT) {
            return (float) $raw;
        }

        return (string) $raw;
    }

    private function valuesEqual(mixed $a, mixed $b, string $type): bool
    {
        if ($type === AppSetting::TYPE_FLOAT) {
            return abs(((float) $a) - ((float) $b)) < 1e-9;
        }

        return $a === $b;
    }

    public function render()
    {
        return view('livewire.admin.settings.index');
    }
}
