<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Архивный (отключённый) сотрудник выходит из системы на первом же запросе.
 *
 * LoginRequest не пускает архивного при ВХОДЕ, но уже открытая сессия (и
 * «запомнить меня») продолжала работать: отключённый менеджер видел заявки,
 * пока сам не выйдет.
 */
class LogoutArchivedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user !== null && $user->archived_at !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Учётная запись деактивирована.']);
        }

        return $next($request);
    }
}
