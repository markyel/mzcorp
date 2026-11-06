<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Плашка «Закрыт на реконструкцию» поверх всего интерфейса, включая вход.
 *
 * Пропускает администраторов и адреса из services.reconstruction.allow_emails.
 * Чтобы такой сотрудник мог войти, когда закрыта и форма входа, есть обходная
 * ссылка ?mz_pass=<ключ> — она ставит куку на этот браузер.
 *
 * Публичные адреса (фиды Директа и Liftway, обложки постов, лента новостей)
 * плашку не получают: их читают площадки, а не люди. API (боты, вебхуки) идёт
 * мимо web-группы и не затронут вовсе.
 */
class ReconstructionPlaque
{
    private const COOKIE = 'mz_pass';

    private const PUBLIC_PATHS = ['feeds/*', 'media/cover/*', 'news', 'news/*', 'arcade/*', 'up', 'images/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.reconstruction.enabled') || $request->is(self::PUBLIC_PATHS)) {
            return $next($request);
        }

        $key = (string) config('services.reconstruction.bypass_key');
        if ($key !== '' && hash_equals($key, (string) $request->query('mz_pass', ''))) {
            Cookie::queue(self::COOKIE, hash('sha256', $key), 60 * 24 * 30);

            return redirect()->to($request->url());
        }

        $user = Auth::user();
        if ($user !== null) {
            $allowed = in_array(mb_strtolower((string) $user->email), (array) config('services.reconstruction.allow_emails'), true)
                || $user->hasRole(Role::Admin->value);
            if ($allowed) {
                return $next($request);
            }
        } elseif ($key !== '' && hash_equals(hash('sha256', $key), (string) $request->cookie(self::COOKIE, ''))) {
            // Гость с обходной кукой — только чтобы войти; после входа решает список.
            return $next($request);
        }

        return response()->view('reconstruction', [], 503)->header('Retry-After', '3600');
    }
}
