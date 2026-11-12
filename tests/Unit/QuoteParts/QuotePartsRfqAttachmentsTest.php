<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Вложения запроса через QuoteParts: фото позиции и файлы уходят base64 вместе с запросом,
 * в пределах лимита; пропавший файл не ломает отправку.
 */
class QuotePartsRfqAttachmentsTest extends TestCase
{
    public function test_files_go_base64_within_the_limit(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('mail/dispatch-staging/1/foto.jpg', 'jpeg-bytes');
        Storage::disk('public')->put('mail/1/a/drawing.pdf', '%PDF-1.4');
        Storage::disk('local')->put('mail/dispatch-staging/1/big.zip', str_repeat('x', 200));
        config(['services.quoteparts.attachments_max_bytes' => 100]);

        $service = app(QuotePartsRfqService::class);
        $method = new \ReflectionMethod($service, 'attachments');
        $out = $method->invoke($service, [
            ['path' => 'mail/dispatch-staging/1/foto.jpg', 'name' => 'foto_vyklyuchatel.jpg', 'mime' => 'image/jpeg'],
            ['path' => 'mail/1/a/drawing.pdf', 'disk' => 'public', 'name' => 'drawing.pdf', 'mime' => 'application/pdf'],
            ['path' => 'mail/dispatch-staging/1/missing.png', 'name' => 'missing.png', 'mime' => 'image/png'],
            ['path' => 'mail/dispatch-staging/1/big.zip', 'name' => 'big.zip', 'mime' => 'application/zip'],
        ]);

        $this->assertSame([
            ['name' => 'foto_vyklyuchatel.jpg', 'mime' => 'image/jpeg', 'content' => base64_encode('jpeg-bytes')],
            ['name' => 'drawing.pdf', 'mime' => 'application/pdf', 'content' => base64_encode('%PDF-1.4')],
        ], $out);
        $rfq = new \App\Models\QuotePartsRfq;
        $rfq->skippedFiles = (new \ReflectionProperty($service, 'skippedFiles'))->getValue($service);
        $this->assertSame(['missing.png', 'big.zip'], $rfq->skippedFiles);
        $this->assertStringContainsString('missing.png, big.zip', QuotePartsRfqService::skippedNote($rfq));
    }

    /** Фото с телефона сверх лимита не отбрасывается, а уходит ужатым до 2048 px JPEG (MZ2026-00935, 12.11.2026). */
    public function test_a_big_phone_photo_goes_shrunk_instead_of_being_dropped(): void
    {
        Storage::fake('local');
        $img = imagecreatetruecolor(4000, 3000);
        for ($i = 0; $i < 4000; $i += 3) {
            imageline($img, $i, 0, 4000 - $i, 2999, imagecolorallocate($img, $i % 256, ($i * 7) % 256, ($i * 13) % 256));
        }
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $this->assertGreaterThan(1_500_000, strlen($png), 'the test photo must be big');
        Storage::disk('local')->put('mail/dispatch-staging/1/IMG_1502.png', $png);
        config(['services.quoteparts.attachments_max_bytes' => 1_400_000]);

        $service = app(QuotePartsRfqService::class);
        $out = (new \ReflectionMethod($service, 'attachments'))->invoke($service, [
            ['path' => 'mail/dispatch-staging/1/IMG_1502.png', 'name' => 'IMG_1502.png', 'mime' => 'image/png'],
        ]);

        $this->assertCount(1, $out);
        $this->assertSame('IMG_1502.jpg', $out[0]['name']);
        $this->assertSame('image/jpeg', $out[0]['mime']);
        $size = getimagesizefromstring(base64_decode($out[0]['content']));
        $this->assertSame([2048, 1536], [$size[0], $size[1]]);
    }
}
