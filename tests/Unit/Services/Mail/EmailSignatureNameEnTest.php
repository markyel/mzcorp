<?php

namespace Tests\Unit\Services\Mail;

use App\Models\User;
use App\Services\Mail\EmailSignatureService;
use Tests\TestCase;

/**
 * Имя латиницей в подписи: из профиля, а без него — транслитом. EVE (QP-4QV2Z0, 30.10.2026) просила английское
 * имя: в подписи было только «Головнев Владимир», name_en пуст у 10 из 14 сотрудников.
 */
class EmailSignatureNameEnTest extends TestCase
{
    private function user(array $attrs): User
    {
        return (new User)->forceFill($attrs + ['email' => 'v.golovnev@myzip.ru']);
    }

    public function test_the_profile_name_comes_first(): void
    {
        $this->assertSame('Vladimir Golovnev', EmailSignatureService::nameEn($this->user(['name' => 'Головнев Владимир', 'name_en' => 'Vladimir Golovnev'])));
    }

    public function test_without_it_the_russian_name_is_transliterated(): void
    {
        $user = $this->user(['name' => 'Головнев Владимир', 'name_en' => null]);
        $this->assertSame('Golovnev Vladimir', EmailSignatureService::nameEn($user));

        $sig = app(EmailSignatureService::class)->render($user);
        $this->assertStringContainsString('Головнев Владимир / Golovnev Vladimir', $sig['plain']);
        $this->assertStringContainsString('/ Golovnev Vladimir', $sig['html']);
    }

    public function test_a_latin_name_is_not_repeated(): void
    {
        $this->assertSame('', EmailSignatureService::nameEn($this->user(['name' => 'Ella', 'name_en' => ''])));
    }
}
