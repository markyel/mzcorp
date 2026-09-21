<?php

namespace App\Livewire\Direct;

use App\Models\AppSetting;
use App\Models\CatalogItem;
use App\Models\DirectAdText;
use App\Services\Direct\DirectAdPlanService;
use App\Services\Direct\DirectAdTextService;
use App\Services\Direct\DirectAdTone;
use App\Services\Direct\DirectApiClient;
use App\Services\Direct\DirectCandidateService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Раздел «Директ» — управление рекламой по складу через API. Только админ.
 *
 * Первый шаг: сколько объявлений держим в Директе, очередь позиций под эту
 * настройку и проверка связи с API. Создание и остановка объявлений придут
 * следующими шагами — здесь пока ничего наружу не пишется.
 */
class Index extends Component
{
    /** Ключ настройки «сколько объявлений держим» в app_settings. */
    public const SETTING_ADS_LIMIT = 'direct.ads_limit';

    public const DEFAULT_ADS_LIMIT = 10;

    public const MIN_ADS_LIMIT = 1;

    public const MAX_ADS_LIMIT = 500;

    /** Ключ настройки «тон рекламных текстов». */
    public const SETTING_AD_TONE = 'direct.ad_tone';

    /**
     * Насколько глубже лимита готовим тексты. Объявление правится без
     * повторной модерации только до публикации, поэтому очередь «на подходе»
     * должна быть написана и вычитана заранее.
     */
    public const PREPARE_AHEAD = 10;

    /** Сколько позиций пишем за одно нажатие — чтобы запрос не висел минутами. */
    public const BULK_LIMIT = 25;

    public int $adsLimit = self::DEFAULT_ADS_LIMIT;

    public string $adTone = DirectAdTone::DEFAULT;

    public ?string $notice = null;

    public ?string $error = null;

    /** Результат последней проверки связи: баллы и кампании аккаунта. */
    public ?array $check = null;

    public function mount(SettingsService $settings): void
    {
        $this->ensureAdmin();
        $this->adsLimit = self::clamp((int) $settings->get(self::SETTING_ADS_LIMIT, self::DEFAULT_ADS_LIMIT));
        $this->adTone = DirectAdTone::normalize($settings->get(self::SETTING_AD_TONE, DirectAdTone::DEFAULT));
    }

    public static function clamp(int $value): int
    {
        return max(self::MIN_ADS_LIMIT, min(self::MAX_ADS_LIMIT, $value));
    }

    public function saveLimit(SettingsService $settings): void
    {
        $this->ensureAdmin();
        $before = (int) $settings->get(self::SETTING_ADS_LIMIT, self::DEFAULT_ADS_LIMIT);
        $this->adsLimit = self::clamp($this->adsLimit);

        $settings->set(
            self::SETTING_ADS_LIMIT,
            (string) $this->adsLimit,
            AppSetting::TYPE_INT,
            Auth::id(),
            'Сколько объявлений держим в Яндекс.Директе одновременно',
        );

        unset($this->queue, $this->plan);
        $this->notice = $before === $this->adsLimit
            ? "В работе {$this->adsLimit} позиций."
            : "Было {$before}, стало {$this->adsLimit} позиций в работе.";
    }

    /** Очередь: берём с запасом, чтобы показать и тех, кто ждёт за порогом. */
    #[Computed]
    public function queue()
    {
        return app(DirectCandidateService::class)->queue($this->planDepth());
    }

    /** Глубина подготовки текстов: ротация плюс ближайший резерв. */
    public function planDepth(): int
    {
        return max(20, $this->adsLimit + self::PREPARE_AHEAD);
    }

    #[Computed]
    public function readyCount(): int
    {
        return app(DirectCandidateService::class)->readyCount();
    }

    /** Связь с API: живой запрос campaigns.get — он же показывает остаток баллов. */
    public function checkConnection(DirectApiClient $api): void
    {
        $this->ensureAdmin();
        $this->error = null;
        $this->notice = null;

        $res = $api->call('campaigns', 'get', [
            'SelectionCriteria' => (object) [],
            'FieldNames' => ['Id', 'Name', 'Type', 'State', 'Status'],
        ]);

        if (! $res['ok']) {
            $this->check = null;
            $this->error = sprintf(
                'Директ ответил: %s%s',
                $res['error']['message'] ?? 'ошибка',
                ($res['error']['code'] ?? 0) ? ' (код '.$res['error']['code'].')' : '',
            );

            return;
        }

        $this->check = [
            'units' => $res['units'],
            'campaigns' => array_map(fn ($c) => [
                'id' => $c['Id'] ?? null,
                'name' => $c['Name'] ?? '',
                'state' => $c['State'] ?? '',
                'status' => $c['Status'] ?? '',
            ], $res['result']['Campaigns'] ?? []),
            'at' => now()->format('H:i'),
        ];
        $this->notice = 'Связь есть.';
    }

    public function refreshQueue(): void
    {
        app(DirectCandidateService::class)->forget();
        unset($this->queue, $this->readyCount, $this->excluded, $this->plan);
        $this->notice = 'Очередь пересобрана.';
    }

    /** Позиции, снятые с рекламы вручную. */
    #[Computed]
    public function excluded()
    {
        return app(DirectCandidateService::class)->excluded();
    }

    /**
     * План публикации: что уйдёт в Директ при текущем лимите и что готовим
     * следом. Тексты пишем на всю глубину, а не только на ротацию.
     */
    #[Computed]
    public function plan()
    {
        return app(DirectAdPlanService::class)->plan($this->adsLimit, $this->planDepth());
    }

    /**
     * Убрать позицию из рекламы: по складу и цене она проходит, но сама по себе
     * спросом не пользуется (комплектующее к другому товару, расходник).
     * Исключение действует и на очередь, и на YML-фид.
     */
    public function excludeItem(string $sku, ?string $reason = null): void
    {
        $this->ensureAdmin();
        $excluded = app(DirectCandidateService::class)->exclude($sku, $reason, Auth::user());
        unset($this->queue, $this->readyCount, $this->excluded, $this->plan);

        $this->notice = $excluded === null
            ? "Позиция {$sku} не найдена в каталоге."
            : "{$sku} убрана из рекламы — из очереди и из фида.";
    }

    /** Тон рекламных текстов — на уже написанные объявления не влияет. */
    public function saveTone(SettingsService $settings): void
    {
        $this->ensureAdmin();
        $this->adTone = DirectAdTone::normalize($this->adTone);

        $settings->set(
            self::SETTING_AD_TONE,
            $this->adTone,
            AppSetting::TYPE_STRING,
            Auth::id(),
            'Каким тоном модель пишет тексты объявлений Директа',
        );

        unset($this->plan);
        $this->notice = 'Тон рекламы: '.mb_strtolower(DirectAdTone::label($this->adTone))
            .'. Уже написанные объявления остались прежними — перепишите их кнопкой, если нужно.';
    }

    /**
     * Написать объявление моделью. Правила остаются страховкой: если модель
     * выдумала бренд или модель оборудования, поле отбрасывается и остаётся
     * прежний вариант.
     */
    public function generateAd(string $sku, DirectAdTextService $texts): void
    {
        $this->ensureAdmin();
        $item = $this->queue->firstWhere('sku', $sku);
        if ($item === null) {
            $this->error = "Позиция {$sku} не найдена в очереди.";

            return;
        }

        $result = $texts->generate($item, Auth::user(), $this->adTone);
        unset($this->plan);

        if ($result === null) {
            $this->error = "{$sku}: модель не дала пригодного текста — оставил вариант правила.";

            return;
        }
        $this->notice = "{$sku}: объявление написано — «{$result->title}».";
    }

    /**
     * Написать тексты всем позициям очереди, где их ещё нет — включая те, что
     * ждут за порогом ротации. В этом и смысл: объявление правится без
     * повторной модерации только до публикации.
     */
    public function generateMissing(DirectAdTextService $texts): void
    {
        $this->ensureAdmin();
        $done = 0;
        $skipped = 0;

        foreach ($this->plan as $row) {
            if ($row['source'] !== DirectAdText::SOURCE_RULE) {
                continue;
            }
            if ($done + $skipped >= self::BULK_LIMIT) {
                break;
            }
            $item = $this->queue->firstWhere('sku', $row['sku']);
            if ($item === null) {
                continue;
            }
            $texts->generate($item, Auth::user(), $this->adTone) === null ? $skipped++ : $done++;
        }

        unset($this->plan);
        $this->notice = $done + $skipped === 0
            ? 'Все позиции очереди уже написаны.'
            : "Написано объявлений: {$done}".($skipped ? ", отклонено проверкой: {$skipped}." : '.');
    }

    /** Правка поля руками — она сильнее и правила, и модели. */
    public function saveField(string $sku, string $field, string $value, DirectAdTextService $texts): void
    {
        $this->ensureAdmin();
        if (! in_array($field, DirectAdText::FIELDS, true)) {
            return;
        }

        $item = CatalogItem::query()->where('sku', $sku)->first(['id', 'sku', 'name']);
        if ($item === null || trim($value) === '') {
            $this->error = 'Пустое поле не сохраняю.';

            return;
        }

        $saved = $texts->save($item, [$field => $value], DirectAdText::SOURCE_MANUAL, Auth::user(), null, $this->adTone);
        unset($this->plan);
        $this->notice = "{$sku}: сохранено — «{$saved->{$field}}».";
    }

    /** Вернуть тексты, собранные правилами. */
    public function resetAd(string $sku, DirectAdTextService $texts): void
    {
        $this->ensureAdmin();
        $texts->forget($sku);
        unset($this->plan);
        $this->notice = "{$sku}: вернул тексты по правилам.";
    }

    public function restoreItem(string $sku): void
    {
        $this->ensureAdmin();
        app(DirectCandidateService::class)->restore($sku);
        unset($this->queue, $this->readyCount, $this->excluded, $this->plan);
        $this->notice = "{$sku} возвращена в очередь.";
    }

    public function dismiss(): void
    {
        $this->notice = null;
        $this->error = null;
    }

    public function render()
    {
        $cfg = config('services.yandex_direct');

        return view('livewire.direct.index', [
            'tones' => DirectAdTone::all(),
            'sandbox' => (bool) ($cfg['sandbox'] ?? true),
            'endpoint' => (string) ($cfg['endpoint'] ?? ''),
            'hasToken' => trim((string) ($cfg['token'] ?? '')) !== '',
            'feedToken' => trim((string) ($cfg['feed']['token'] ?? '')) !== '',
        ]);
    }

    private function ensureAdmin(): void
    {
        abort_unless(Auth::user()?->hasRole('admin'), 403);
    }
}
