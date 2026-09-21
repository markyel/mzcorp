<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectPublisherService as Publisher;
use Tests\TestCase;

/**
 * Разбор ответов Директа. Главная ловушка: Директ отвечает HTTP 200 и кладёт
 * ошибку отдельного объекта внутрь AddResults — «ок» на уровне запроса ещё не
 * значит, что группа создана. Без БД и без сети.
 */
class DirectPublisherServiceTest extends TestCase
{
    public function test_region_ids_fall_back_to_russia(): void
    {
        config(['services.yandex_direct.region_ids' => '']);
        $this->assertSame([225], Publisher::regionIds());

        config(['services.yandex_direct.region_ids' => '225, 213 ,0']);
        $this->assertSame([225, 213], Publisher::regionIds());
    }

    public function test_added_id_comes_from_the_id_field(): void
    {
        // Директ отдаёт идентификатор в «Id» — и для группы тоже, хотя
        // «AdGroupId» напрашивается. На этом мы потеряли десять групп.
        $this->assertSame(5801416484, Publisher::addedId(['AddResults' => [['Id' => 5801416484, 'Errors' => []]]], 'AdGroupId'));
        $this->assertSame(7, Publisher::addedId(['AddResults' => [['AdGroupId' => 7]]], 'AdGroupId'));
        $this->assertSame(0, Publisher::addedId(['AddResults' => [['Errors' => [['Message' => 'нет']]]]], 'AdGroupId'));
        $this->assertSame(0, Publisher::addedId(null));
    }

    public function test_per_object_errors_are_pulled_out_of_a_successful_response(): void
    {
        $result = [
            'AddResults' => [
                ['Errors' => [['Message' => 'Неверное значение параметра', 'Details' => 'Title: слишком длинный']]],
                ['Id' => 123],
            ],
        ];

        $this->assertStringContainsString('слишком длинный', Publisher::resultErrors($result));
    }

    public function test_no_errors_means_empty_string(): void
    {
        $this->assertSame('', Publisher::resultErrors(['AddResults' => [['Id' => 1], ['Id' => 2]]]));
        $this->assertSame('', Publisher::resultErrors(null));
    }

    public function test_error_text_joins_message_and_detail(): void
    {
        $res = ['error' => ['code' => 54, 'message' => 'Нет прав', 'detail' => 'Доступ к кампании запрещён']];
        $this->assertSame('Нет прав Доступ к кампании запрещён', Publisher::errorText($res));

        $this->assertSame('', Publisher::errorText(['error' => null]));
    }
}
