<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Маршрутный middleware: требует, чтобы у залогиненного пользователя была
 * хотя бы одна из перечисленных ролей.
 *
 *   Route::get(...)->middleware('role:head_of_sales,director')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyRole($roles)) {
            abort(403, 'Недостаточно прав.');
        }

        return $next($request);
    }
}
