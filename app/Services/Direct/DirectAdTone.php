<?php

namespace App\Services\Direct;

/**
 * Тон рекламных текстов. Тон задаёт, КАК написано объявление, и не трогает то,
 * ЧТО в нём написано: факты берутся только из карточки позиции при любом тоне.
 *
 * Выбор хранится в app_settings (`direct.ad_tone`) и подставляется в промпт.
 * Сменили тон — старые объявления остаются прежними, пока их не перепишут:
 * каждая правка текста заново гонит объявление через модерацию.
 */
class DirectAdTone
{
    public const DEFAULT = 'official';

    /**
     * label — для интерфейса, hint — подсказка под переключателем,
     * prompt — инструкция модели, temperature — насколько ей вольничать.
     */
    public const OPTIONS = [
        'official' => [
            'label' => 'Официальный',
            'hint' => 'Сухо и по делу: наличие, бренд, артикул. Так пишет поставщик снабженцу.',
            'temperature' => 0.2,
            'prompt' => 'Тон официально-деловой. Пиши сухо и по существу, без эмоций, '
                .'восклицаний и обращений. Обращение на «вы», глаголы в третьем лице.',
        ],
        'expert' => [
            'label' => 'Экспертный',
            'hint' => 'Как инженер инженеру: тип узла, применимость, точность формулировок.',
            'temperature' => 0.3,
            'prompt' => 'Тон экспертный, инженерный. Говори точно: тип узла, где применяется, '
                .'чем важна деталь. Никакой рекламной воды, только профессиональная конкретика.',
        ],
        'helpful' => [
            'label' => 'Заботливый',
            'hint' => 'Спокойно объясняем выгоду: есть на складе, цена сразу, отгрузим быстро.',
            'temperature' => 0.4,
            'prompt' => 'Тон спокойный и дружелюбный, на «вы». Подчёркивай удобство для клиента: '
                .'деталь есть на складе, цену дадим сразу, отгрузим быстро. Без фамильярности.',
        ],
        'energetic' => [
            'label' => 'Энергичный',
            'hint' => 'Короткие рубленые фразы и прямой призыв: запросить цену, забрать со склада.',
            'temperature' => 0.5,
            'prompt' => 'Тон энергичный, динамичный. Короткие рубленые фразы, прямой призыв к '
                .'действию в конце текста («запросите цену», «забирайте со склада»). '
                .'Не более одного восклицательного знака во всём тексте.',
        ],
        'young' => [
            'label' => 'Молодёжный',
            'hint' => 'Живая разговорная речь. Уместен для соцсетей, для B2B-поиска рискован.',
            'temperature' => 0.6,
            'prompt' => 'Тон живой и разговорный, современный. Простые слова, ритм короткий. '
                .'Обращение на «вы», но без канцелярита. Никакого сленга, который непонятен '
                .'снабженцу, и не более одного восклицательного знака.',
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::OPTIONS;
    }

    public static function normalize(?string $key): string
    {
        $key = trim((string) $key);

        return isset(self::OPTIONS[$key]) ? $key : self::DEFAULT;
    }

    public static function label(?string $key): string
    {
        return (string) self::OPTIONS[self::normalize($key)]['label'];
    }

    public static function prompt(?string $key): string
    {
        return (string) self::OPTIONS[self::normalize($key)]['prompt'];
    }

    public static function temperature(?string $key): float
    {
        return (float) self::OPTIONS[self::normalize($key)]['temperature'];
    }

    /** Разрешён ли восклицательный знак в тексте — в сухих тонах нет. */
    public static function allowsExclamation(?string $key): bool
    {
        return in_array(self::normalize($key), ['energetic', 'young'], true);
    }
}
