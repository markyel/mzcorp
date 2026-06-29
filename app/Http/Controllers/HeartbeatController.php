<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Приёмник heartbeat-пингов присутствия. Фронтенд (layouts/app.blade.php)
 * шлёт POST каждые ~60с, пока вкладка видима. Пишем текущую минуту в
 * user_activity_minutes (insertOrIgnore — дедуп по unique(user_id, minute)).
 *
 * Намеренно максимально дёшево: один идемпотентный insert, без модели,
 * без событий. Раздел статистики (admin/director) агрегирует эти минуты.
 */
class HeartbeatController extends Controller
{
    public function __invoke(Request $request)
    {
        $userId = $request->user()?->id;
        if ($userId) {
            // Усекаем до минуты — MSK-naive, как все timestamps проекта.
            $minute = now()->startOfMinute();

            DB::table('user_activity_minutes')->insertOrIgnore([
                'user_id' => $userId,
                'minute' => $minute,
            ]);
        }

        return response()->noContent();
    }
}
