<?php

namespace Tests\Unit\Catalog;

use App\Models\Identification\IdentificationPartType;
use App\Services\Catalog\IdentificationLearner;
use Tests\TestCase;

/** О какой детали вопрос менеджера: по словам вопроса, а не по позиции заявки (MZ2026-03414: «Фото замка» у ролика). */
class IdentificationLearnerTypeTest extends TestCase
{
    private function types()
    {
        return collect([
            new IdentificationPartType(['key' => 'roller', 'name' => 'ролик', 'pattern' => '/ролик/iu']),
            new IdentificationPartType(['key' => 'door_lock', 'name' => 'замок двери', 'pattern' => '/замок|замк/iu']),
        ]);
    }

    private function type(string $raw, ?string $posKey, ?string $posName): ?string
    {
        $types = $this->types();

        return IdentificationLearner::questionType($raw, $posKey ? $types->firstWhere('key', $posKey) : null, $posName, $types)?->key;
    }

    public function test_the_part_asked_about_wins_over_the_position(): void
    {
        $this->assertSame('door_lock', $this->type('замок двери', 'roller', 'Ролик замка дверей'));
    }

    public function test_an_unknown_part_is_not_filed_under_the_position(): void
    {
        $this->assertNull($this->type('ограничитель скорости', 'roller', 'Ролик ремня SWORD'));
        $this->assertNull($this->type('ступень', 'roller', 'Ролик ремня SWORD'));
    }

    public function test_the_position_type_is_used_for_the_same_part_or_a_generic_word(): void
    {
        $this->assertSame('roller', $this->type('ступень', 'roller', 'Ролик ступени эскалатора'));
        $this->assertSame('roller', $this->type('деталь', 'roller', 'Ролик ремня SWORD'));
        $this->assertSame('roller', $this->type('', 'roller', 'Ролик ремня SWORD'));
    }
}
