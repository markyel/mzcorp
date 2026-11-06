<?php

namespace Tests\Unit\AI;

use App\Services\AI\OpenAIFallbackGateway;
use PHPUnit\Framework\TestCase;

class OpenAIFallbackGatewayImagesTest extends TestCase
{
    public function test_answers_per_image_merge_their_lists_and_keep_first_scalars(): void
    {
        $merged = json_decode(OpenAIFallbackGateway::mergeJsonAnswers([
            '{"items":[{"article":"KM601001G01","qty":4}],"language":"ru"}',
            "```json\n{\"items\":[{\"article\":\"M14140\",\"qty\":1}],\"language\":\"en\"}\n```",
            '{"items":[]}',
        ]), true);

        $this->assertSame(['KM601001G01', 'M14140'], array_column($merged['items'], 'article'));
        $this->assertSame('ru', $merged['language']);
    }

    public function test_plain_text_answers_are_joined(): void
    {
        $this->assertSame("первая\n\nвторая", OpenAIFallbackGateway::mergeJsonAnswers(['первая', ' вторая ']));
    }

    public function test_images_are_counted_across_messages(): void
    {
        $image = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAA']];
        $payload = ['messages' => [
            ['role' => 'system', 'content' => 'Разбери позиции'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'скан'], $image, $image]],
            ['role' => 'user', 'content' => [$image]],
        ]];

        $this->assertSame(3, OpenAIFallbackGateway::imageCount($payload));
    }
}
