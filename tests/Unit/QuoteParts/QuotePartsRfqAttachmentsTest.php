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
    }
}
