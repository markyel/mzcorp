<?php

namespace App\Http\Controllers\QuoteParts;

use App\Http\Controllers\Controller;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPilot;
use Illuminate\Http\Response;
use Throwable;

/**
 * Файл из обращения к поставщику: QuoteParts отдаёт его по ключу API, сотруднику — через mzcorp.
 */
class ConversationFileController extends Controller
{
    public function __invoke(int $conversation, int $message, int $index, QuotePartsClient $client): Response
    {
        abort_unless(QuotePartsPilot::canConverse(auth()->user()), 403);

        try {
            [$body, $name, $type] = $client->conversationFile($conversation, $message, $index);
        } catch (Throwable) {
            abort(404);
        }

        $inline = str_starts_with($type, 'image/') || $type === 'application/pdf';

        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.str_replace('"', '', $name).'"; filename*=UTF-8\'\''.rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
