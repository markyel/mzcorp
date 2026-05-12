<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth для `POST /api/catalog/import`.
 *
 * Сравнение через hash_equals — защита от timing-attack. Источник секрета
 * — `config('services.catalog_import.token')` (env `CATALOG_IMPORT_TOKEN`).
 * Если env не задан — middleware всех режет 503, чтобы не задеплоить
 * случайно открытый endpoint.
 */
class CatalogImportToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.catalog_import.token', '');
        if ($expected === '') {
            return response()->json([
                'error' => 'catalog_import_disabled',
                'message' => 'CATALOG_IMPORT_TOKEN не задан на сервере',
            ], 503);
        }

        $provided = (string) $request->bearerToken();
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'error' => 'unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
