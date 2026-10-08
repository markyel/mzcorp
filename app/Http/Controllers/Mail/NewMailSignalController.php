<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Services\Mail\NewMailSignalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Опрос «есть ли новая почта» из любого раздела CRM (layouts/app.blade.php).
 * ?after=<id> — курсор, который клиент запомнил в прошлый раз.
 */
class NewMailSignalController extends Controller
{
    public function __invoke(Request $request, NewMailSignalService $signal): JsonResponse
    {
        $after = $request->query('after');
        $afterId = is_numeric($after) && (int) $after > 0 ? (int) $after : null;

        return response()->json($signal->summary($request->user(), $afterId))
            ->header('Cache-Control', 'no-store');
    }
}
