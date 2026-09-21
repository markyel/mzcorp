<?php

namespace App\Livewire\Direct;

use App\Models\AppSetting;
use App\Models\CatalogItem;
use App\Models\DirectAdTitle;
use App\Services\Direct\DirectAdPlanService;
use App\Services\Direct\DirectApiClient;
use App\Services\Direct\DirectCandidateService;
use App\Services\Direct\DirectTitleService;
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

    public int $adsLimit = self::DEFAULT_ADS_LIMIT;

    public ?string $notice = null;

    public ?string $error = null;

    /** Результат последней проверки связи: баллы и кампании аккаунта. */
    public ?array $check = null;

    public function mount(SettingsService $settings): void
    {
        $this->ensureAdmin();
        $this->adsLimit = self::clamp((int) $settings->get(self::SETTING_ADS_LIMIT, self::DEFAULT_ADS_LIMIT));
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
        return app(DirectCandidateService::class)->queue(max(20, $this->adsLimit + 10));
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

    /** План публикации: что именно уйдёт в Директ при текущем лимите. */
    #[Computed]
    public function plan()
    {
        return app(DirectAdPlanService::class)->plan($this->adsLimit);
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

    /**
     * Переписать заголовок моделью. Правила остаются страховкой: если модель
     * выдумала бренд или модель оборудования, результат отбрасывается и
     * заголовок остаётся прежним.
     */
    public function generateTitle(string $sku, DirectTitleService $titles): void
    {
        $this->ensureAdmin();
        $item = app(DirectCandidateService::class)->queue($this->adsLimit)->firstWhere('sku', $sku);
        if ($item === null) {
            $this->error = "Позиция {$sku} не найдена в очереди.";

            return;
        }

        $result = $titles->generate($item, Auth::user());
        unset($this->plan);

        if ($result === null) {
            $this->error = "{$sku}: модель не дала пригодного заголовка — оставил вариант правила.";

            return;
        }
        $this->notice = "{$sku}: заголовок переписан — «{$result->title}».";
    }

    /** Переписать заголовки всем позициям плана, у которых есть замечания. */
    public function generateTitlesForFlagged(DirectTitleService $titles): void
    {
        $this->ensureAdmin();
        $done = 0;
        $skipped = 0;

        foreach ($this->plan as $row) {
            if ($row['warnings'] === [] || $row['title_source'] !== DirectAdTitle::SOURCE_RULE) {
                continue;
            }
            $item = app(DirectCandidateService::class)->queue($this->adsLimit)->firstWhere('sku', $row['sku']);
            if ($item === null) {
                continue;
            }
            $titles->generate($item, Auth::user()) === null ? $skipped++ : $done++;
        }

        unset($this->plan);
        $this->notice = "Переписано заголовков: {$done}".($skipped ? ", отклонено моделью: {$skipped}" : '.');
    }

    /** Правка заголовка руками — она сильнее и правила, и модели. */
    public function saveTitle(string $sku, string $title, DirectTitleService $titles): void
    {
        $this->ensureAdmin();
        $item = CatalogItem::query()->where('sku', $sku)->first(['id', 'sku', 'name']);
        if ($item === null || trim($title) === '') {
            $this->error = 'Пустой заголовок не сохраняю.';

            return;
        }
        $saved = $titles->save($item, $title, DirectAdTitle::SOURCE_MANUAL, Auth::user());
        unset($this->plan);
        $this->notice = "{$sku}: заголовок сохранён — «{$saved->title}».";
    }

    /** Вернуть заголовок, собранный правилом. */
    public function resetTitle(string $sku, DirectTitleService $titles): void
    {
        $this->ensureAdmin();
        $titles->forget($sku);
        unset($this->plan);
        $this->notice = "{$sku}: вернул заголовок по правилу.";
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
