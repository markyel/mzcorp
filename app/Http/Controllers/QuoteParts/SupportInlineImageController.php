<?php

namespace App\Http\Controllers\QuoteParts;

use App\Http\Controllers\Controller;
use App\Services\QuoteParts\QuotePartsPilot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Картинка в тексте ответа поддержки QuoteParts (редактор x-mail.rich-editor на вкладке «Поддержка»):
 * лежит у нас, пока ответ не ушёл, — в папке черновика сотрудника; при отправке SupportRequests::reply
 * передаёт её в QuoteParts inline-вложением, а в HTML ставит cid:. Видит и грузит только сам сотрудник.
 */
class SupportInlineImageController extends Controller
{
    /** Как у картинок в тексте письма почтового клиента (MailInlineImageService::MAX_BYTES) и у приёма в QuoteParts. */
    public const MAX_KB = 10240;

    public function store(Request $request, string $draft): JsonResponse
    {
        abort_unless(QuotePartsPilot::canAnswer($request->user()), 403);
        $request->validate([
            'image' => 'required|file|mimes:png,jpg,jpeg,gif|max:'.self::MAX_KB,
        ], [
            'image.mimes' => 'Поддерживаются PNG, JPG и GIF.',
            'image.max' => 'Картинка больше 10 МБ.',
        ]);

        $file = $request->file('image');
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'png');
        $name = 'qpi-'.Str::lower(Str::random(12)).'.'.($ext === 'jpeg' ? 'jpg' : $ext);
        Storage::disk('local')->putFileAs(self::dir((int) $request->user()->id, $draft), $file, $name);

        return response()->json(['url' => route('quoteparts.support.images.show', [$draft, $name])]);
    }

    public function show(Request $request, string $draft, string $name): StreamedResponse
    {
        abort_unless(QuotePartsPilot::canAnswer($request->user()), 403);
        $path = self::dir((int) $request->user()->id, $draft).'/'.$name;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /** Папка картинок черновика ответа: своя у каждого сотрудника. */
    public static function dir(int $userId, string $draft): string
    {
        return 'quoteparts/support-inline/'.$userId.'/'.$draft;
    }
}
