<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\MessagePersister;
use PHPUnit\Framework\TestCase;

/**
 * Письмо Liftway «Просим выставить счёт — заказ ЗК-2026-0997»: текстовая часть
 * начиналась двумя тысячами символов CSS, запрос шёл после них. Классификатор
 * читает начало тела — увидел стили и тему, записал заявку в постпродажу, и
 * заявка не создалась вовсе.
 *
 * Отсюда правило: текстовая часть со стилями не текст, берём HTML и чистим
 * сами. Ошибиться в другую сторону дороже — настоящее письмо потерять нельзя,
 * поэтому признак строгий.
 */
class LooksLikeCssTest extends TestCase
{
    public function test_a_stylesheet_smuggled_into_the_text_part(): void
    {
        $text = <<<'CSS'
Просим выставить счёт — Liftway.ru

    body{margin:0;padding:0;font-family:-apple-system,Arial,sans-serif;background:#F8F9FB}
    .wrap{max-width:600px;margin:0 auto;padding:24px 16px}
    .card{background:#fff;border-radius:12px;border:1px solid #E5E7EB}
    .head h1{margin:0;color:#fff;font-size:18px;font-weight:700}
CSS;

        $this->assertTrue(MessagePersister::looksLikeCss($text));
    }

    public function test_an_ordinary_letter_is_not_a_stylesheet(): void
    {
        $this->assertFalse(MessagePersister::looksLikeCss(
            "Добрый день!\n\nПросим подтвердить наличие и выставить счёт:\n"
            ."1. Контакт дверей 60мм — 2 компл.\n2. Вкладыш башмака ДК — 8 шт.\n\nС уважением, Иванов"
        ));
    }

    public function test_a_letter_with_a_stray_brace_is_not_a_stylesheet(): void
    {
        // Шаблонные хвосты и фрагменты кода в переписке встречаются — одного
        // совпадения мало, иначе мы начнём выбрасывать живые письма.
        $this->assertFalse(MessagePersister::looksLikeCss(
            "Добрый день! В прошлом письме был шаблон {name: значение; } — поправьте, пожалуйста."
        ));
    }

    public function test_empty_text_is_not_a_stylesheet(): void
    {
        $this->assertFalse(MessagePersister::looksLikeCss(''));
    }
}
