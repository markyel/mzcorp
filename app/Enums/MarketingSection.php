<?php

namespace App\Enums;

/**
 * Разделы ежемесячного отчёта об оказанных маркетинговых услугах
 * (Приложение № 1 к договору с ИП Маркелов).
 *
 * Тот же перечень используется как рубрикатор плана, заметок и журнала работ:
 * запись, сделанная в разделе «Реклама», попадает в пункт 2 отчёта без ручного
 * переноса. Порядок кейсов = порядок пунктов формы.
 */
enum MarketingSection: string
{
    case Ads = 'ads';
    case Base = 'base';
    case Social = 'social';
    case Positioning = 'positioning';
    case Feedback = 'feedback';
    case Contractors = 'contractors';
    case Analytics = 'analytics';

    /** Заголовок пункта формы (без номера — номер даёт порядок в forms()). */
    public function label(): string
    {
        return match ($this) {
            self::Ads => 'Реклама и продвижение',
            self::Base => 'Клиентская база и прямые коммуникации',
            self::Social => 'Социальные сети и иные каналы продвижения',
            self::Positioning => 'Позиционирование и рекламные материалы',
            self::Feedback => 'Обратная связь и исследования клиентов',
            self::Contractors => 'Работа с подрядчиками и сервисами',
            self::Analytics => 'Аналитика и рекомендации',
        };
    }

    /** Короткая подпись для чипов и селектов в UI. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Ads => 'Реклама',
            self::Base => 'База и рассылки',
            self::Social => 'Соцсети',
            self::Positioning => 'Позиционирование',
            self::Feedback => 'Обратная связь',
            self::Contractors => 'Подрядчики',
            self::Analytics => 'Аналитика',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Ads => '📣',
            self::Base => '✉️',
            self::Social => '💬',
            self::Positioning => '🎯',
            self::Feedback => '🗣',
            self::Contractors => '🤝',
            self::Analytics => '📊',
        };
    }

    /**
     * Номер пункта в форме Приложения № 1. Пункт 1 — «Основные выполненные
     * задачи», он собирается из журнала целиком, поэтому нумерация разделов
     * начинается с 2.
     */
    public function formNumber(): int
    {
        return match ($this) {
            self::Ads => 2,
            self::Base => 3,
            self::Social => 4,
            self::Positioning => 5,
            self::Feedback => 6,
            self::Contractors => 7,
            self::Analytics => 8,
        };
    }

    /**
     * Подписи полей раздела в форме отчёта: ключ payload → подпись.
     * Ключи совпадают с формулировками Приложения № 1.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Ads => [
                'works' => 'Выполненные работы',
                'changes' => 'Проведённые изменения, запуски и эксперименты',
                'conclusions' => 'Выводы',
            ],
            self::Base => [
                'works' => 'Выполненные работы',
                'campaigns' => 'Проведённые рассылки и иные коммуникации',
                'base_size' => 'Размер/изменение используемой базы',
                'results' => 'Результаты',
            ],
            self::Social => [
                'works' => 'Работы по Telegram, VK, Дзен, иным каналам',
                'results' => 'Результаты и наблюдения',
            ],
            self::Positioning => [
                'works' => 'Выполненные работы',
                'materials' => 'Подготовленные материалы, концепции и предложения',
            ],
            self::Feedback => [
                'works' => 'Проведённые интервью, опросы, сбор обратной связи',
                'findings' => 'Основные выявленные наблюдения',
            ],
            self::Contractors => [
                'works' => 'Проведённые работы и принятые решения',
                'proposals' => 'Предложения по изменению сервисов, инструментов, подрядчиков',
            ],
            self::Analytics => [
                'conclusions' => 'Основные выводы по результатам месяца',
                'problems' => 'Выявленные проблемы и точки роста',
                'recommendations' => 'Предложения и рекомендации',
            ],
        };
    }

    /** Показатели пункта 2 формы: ключ → подпись. */
    public const AD_METRICS = [
        'spend' => 'Рекламные расходы, руб.',
        'impressions' => 'Показы',
        'clicks' => 'Переходы',
        'leads' => 'Обращения / целевые действия',
        'cpl' => 'Стоимость обращения, руб.',
        'other' => 'Иные показатели',
    ];

    /** @return array<int, self> */
    public static function ordered(): array
    {
        return self::cases();
    }
}
