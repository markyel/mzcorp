<?php

namespace App\Enums;

/**
 * Категория сложности заявки — выводится из `requests.complexity_score`
 * по порогам (AppSetting `complexity.thresholds`).
 *
 * Score = Σ MatchPath::defaultWeight() по всем active items в заявке.
 *
 * Default-пороги (тюнятся через AppSetting):
 *   0–5   → Easy      (1-5 M-артикулов)
 *   6–18  → Normal    (5-9 OEM или mix easy)
 *   19–45 → Hard      (несколько unmatched + сматч)
 *   46+   → VeryHard  (6+ unmatched позиций)
 */
enum ComplexityLevel: string
{
    case Easy = 'easy';
    case Normal = 'normal';
    case Hard = 'hard';
    case VeryHard = 'very_hard';

    public function label(): string
    {
        return match ($this) {
            self::Easy => 'Лёгкая',
            self::Normal => 'Средняя',
            self::Hard => 'Сложная',
            self::VeryHard => 'Очень сложная',
        };
    }

    /**
     * Короткая версия label'а для узких UI (chip в Pool колонке 110px).
     * Только Easy/Normal/Hard остаются как есть, VeryHard → «Оч. сложная»
     * чтобы вмещаться в одну строку с иконкой и score.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Easy => 'Лёгкая',
            self::Normal => 'Средняя',
            self::Hard => 'Сложная',
            self::VeryHard => 'Оч. сложная',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Easy => '○',
            self::Normal => '◔',
            self::Hard => '◑',
            self::VeryHard => '●',
        };
    }

    /**
     * Tone для chip UI. Совпадает с шкалой attention в Pool —
     * серый → sky → amber → red.
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Easy => 'bg-neutral-100 text-fg-3 border-border',
            self::Normal => 'bg-sky-50 text-sky-700 border-sky-200',
            self::Hard => 'bg-amber-50 text-amber-700 border-amber-200',
            self::VeryHard => 'bg-red-50 text-red-700 border-red-200',
        };
    }

    /**
     * Default-пороги. AppSetting `complexity.thresholds` может переопределить.
     * Формат: ['easy_max' => 5, 'normal_max' => 18, 'hard_max' => 45].
     * Score > hard_max → VeryHard.
     *
     * @return array{easy_max: int, normal_max: int, hard_max: int}
     */
    public static function defaultThresholds(): array
    {
        return [
            'easy_max' => 5,
            'normal_max' => 18,
            'hard_max' => 45,
        ];
    }

    /**
     * Резолвит уровень по score и порогам.
     *
     * @param  array{easy_max?: int, normal_max?: int, hard_max?: int}  $thresholds
     */
    public static function fromScore(int $score, array $thresholds = []): self
    {
        $t = array_merge(self::defaultThresholds(), $thresholds);
        return match (true) {
            $score <= $t['easy_max'] => self::Easy,
            $score <= $t['normal_max'] => self::Normal,
            $score <= $t['hard_max'] => self::Hard,
            default => self::VeryHard,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
