<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ВРЕМЕННО (диагностика 419 на suppliers.show): логирует payload update-запроса
 * Livewire для компонента suppliers.show + итоговый статус, чтобы поймать, какое
 * свойство/значение вызывает TypeError → abort(419). Удалить после разбора.
 */
class DebugLivewirePayload
{
    public function handle(Request $request, Closure $next): Response
    {
        $isUpdate = str_contains($request->path(), '/update')
            && str_contains($request->path(), 'livewire');

        $relevant = false;
        if ($isUpdate) {
            $components = (array) $request->input('components', []);
            foreach ($components as $c) {
                $name = data_get($c, 'snapshot');
                if (is_string($name) && str_contains($name, 'suppliers.show')) {
                    $relevant = true;
                    $snap = json_decode((string) data_get($c, 'snapshot'), true);
                    Log::warning('LW419-DEBUG payload', [
                        'memo_name' => data_get($snap, 'memo.name'),
                        'updates' => data_get($c, 'updates'),
                        'calls' => array_map(
                            fn ($x) => ['method' => data_get($x, 'method'), 'params_types' => array_map('gettype', (array) data_get($x, 'params', []))],
                            (array) data_get($c, 'calls', []),
                        ),
                        'data_keys' => is_array(data_get($snap, 'data')) ? array_keys(data_get($snap, 'data')) : null,
                        'reply_files_raw' => data_get($snap, 'data.replyFiles'),
                    ]);
                    break;
                }
            }
        }

        $response = $next($request);

        if ($relevant) {
            Log::warning('LW419-DEBUG response', ['status' => $response->getStatusCode()]);
        }

        return $response;
    }
}
