<?php

namespace Tests\Unit\Photos;

use App\Services\Photos\ClippingMagicClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Clipping Magic: загрузка с format=result отдаёт JPEG на белом фоне; ошибки — понятным текстом. */
class ClippingMagicClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.clippingmagic.id' => '123', 'services.clippingmagic.secret' => 'x', 'services.clippingmagic.test' => false]);
    }

    private function file(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cm');
        file_put_contents($path, 'jpeg-bytes');

        return $path;
    }

    public function test_clip_asks_for_a_white_jpeg_result(): void
    {
        Http::fake(['clippingmagic.com/api/v1/images' => Http::response('RESULT', 200, ['x-amz-meta-id' => '2346'])]);

        $r = app(ClippingMagicClient::class)->clip($this->file(), 'M12345.jpg');

        $this->assertSame('RESULT', $r['body']);
        $this->assertSame('2346', $r['id']);
        Http::assertSent(function (Request $req) {
            $fields = collect($req->data())->pluck('contents', 'name');

            return $fields['format'] === 'result' && $fields['background.color'] === '#FFFFFF'
                && $fields['output.opaqueFileFormat'] === 'jpeg' && ! $fields->has('test')
                && $req->hasHeader('Authorization', 'Basic '.base64_encode('123:x'));
        });
    }

    public function test_no_credits_is_said_plainly(): void
    {
        Http::fake(['clippingmagic.com/api/v1/images' => Http::response(['error' => ['status' => 402, 'code' => 1500, 'message' => 'Not enough credits']], 402)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('закончились кредиты');
        app(ClippingMagicClient::class)->clip($this->file(), 'M12345.jpg');
    }

    public function test_not_configured(): void
    {
        config(['services.clippingmagic.id' => '']);

        $this->assertFalse(app(ClippingMagicClient::class)->isConfigured());
        $this->expectException(RuntimeException::class);
        app(ClippingMagicClient::class)->clip($this->file(), 'M1.jpg');
    }
}
