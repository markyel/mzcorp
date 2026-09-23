<?php

namespace App\Enums;

/**
 * Грани медиапрофиля компании.
 *
 * Профиль собирается как набор утверждений о том, кто мы и как о себе
 * говорим: «фирменный цвет красный», «старейший импортёр лифтовых запчастей»,
 * «профессионально, но не сухо». Дальше через эти же грани будут проходить
 * маркетинговые материалы — новости, рассылки, буклеты, — поэтому рубрики
 * выбраны так, чтобы каждая давала проверяемое требование к тексту, а не
 * просто ярлык.
 */
enum MediaProfileFacet: string
{
    /** Кто мы и чем отличаемся — факты, на которые опирается реклама. */
    case Positioning = 'positioning';

    /** Как говорим: тон, лексика, длина фраз, обращение к читателю. */
    case Voice = 'voice';

    /** Как выглядим: цвет, шрифт, логотип, требования к иллюстрациям. */
    case Visual = 'visual';

    /** Чем запоминаемся: календарь, фирменные приёмы, традиции. */
    case Signature = 'signature';

    /** Кому говорим: кто читатель, что для него важно. */
    case Audience = 'audience';

    /** Чего не делаем: запреты, стоп-слова, чужие приёмы. */
    case Taboo = 'taboo';

    public function label(): string
    {
        return match ($this) {
            self::Positioning => 'Кто мы',
            self::Voice => 'Как говорим',
            self::Visual => 'Как выглядим',
            self::Signature => 'Чем запоминаемся',
            self::Audience => 'Кому говорим',
            self::Taboo => 'Чего не делаем',
        };
    }

    /** Подсказка редактору: что именно сюда писать. */
    public function hint(): string
    {
        return match ($this) {
            self::Positioning => 'Факты и отличия: старейший импортёр, склад в Москве, свой инженерный отдел',
            self::Voice => 'Тон и манера: профессионально, но не сухо; без канцелярита; на «вы»',
            self::Visual => 'Фирменный стиль: красный основной цвет, логотип слева, фото деталей на светлом фоне',
            self::Signature => 'Запоминающееся: ежегодный календарь с фотомоделями, свои обзоры рынка',
            self::Audience => 'Читатель: снабженец лифтовой компании, ценит срок и наличие, а не красоту',
            self::Taboo => 'Запреты: не обещаем сроки, которых нет; не сравниваем с конкурентами по именам',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Positioning => '🏛',
            self::Voice => '🗣',
            self::Visual => '🎨',
            self::Signature => '⭐',
            self::Audience => '👥',
            self::Taboo => '⛔',
        };
    }

    /** @return array<int, self> */
    public static function ordered(): array
    {
        return [self::Positioning, self::Voice, self::Visual, self::Signature, self::Audience, self::Taboo];
    }
}
