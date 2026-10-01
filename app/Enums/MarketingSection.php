<?php

namespace App\Enums;

/**
 * Направления регулярных услуг по договору с ИП Маркелов (Приложение № 1
 * «Перечень регулярных услуг»; в отчёте по форме Приложения № 2 — строки
 * таблицы «Регулярные услуги»).
 *
 * Тот же перечень используется как рубрикатор плана, заметок и журнала работ:
 * запись, сделанная в разделе «Реклама», попадает в своё направление отчёта
 * без ручного переноса. Значения кейсов остались от прежней формы (в базе
 * лежат записи), названия и порядок — по новой: ordered() и formNumber().
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
            self::Analytics => 'Маркетинговая аналитика и мониторинг',
            self::Ads => 'Сопровождение рекламных инструментов Заказчика',
            self::Base => 'Клиентская база и прямые коммуникации',
            self::Social => 'Каналы коммуникации и присутствие компании',
            self::Positioning => 'Позиционирование и информационные материалы',
            self::Feedback => 'Рынок, конкуренты и клиентская обратная связь',
            self::Contractors => 'Организационно-консультационное сопровождение',
        };
    }

    /** Короткая подпись для чипов и селектов в UI. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Ads => 'Реклама',
            self::Base => 'База и рассылки',
            self::Social => 'Каналы и соцсети',
            self::Positioning => 'Позиционирование',
            self::Feedback => 'Рынок и обратная связь',
            self::Contractors => 'Организация и консультации',
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

    /** Номер направления в Приложении № 1 и в таблице регулярных услуг отчёта. */
    public function formNumber(): int
    {
        return match ($this) {
            self::Analytics => 1,
            self::Ads => 2,
            self::Base => 3,
            self::Social => 4,
            self::Positioning => 5,
            self::Feedback => 6,
            self::Contractors => 7,
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

    /** Направления в порядке договора (Приложение № 1). @return array<int, self> */
    public static function ordered(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $a->formNumber() <=> $b->formNumber());

        return $cases;
    }
}
