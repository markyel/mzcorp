<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'catalog.import.token' => \App\Http\Middleware\CatalogImportToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ВРЕМЕННО (диагностика 419): логируем детали TokenMismatch, чтобы
        // понять, почему CSRF не проходит на свежей сессии. Удалить после разбора.
        $exceptions->report(function (\Illuminate\Session\TokenMismatchException $e) {
            $req = request();
            $raw = (string) $req->headers->get('Cookie');
            $names = [];
            foreach (explode(';', $raw) as $part) {
                $nm = trim(explode('=', $part, 2)[0] ?? '');
                if ($nm !== '') {
                    $names[$nm] = ($names[$nm] ?? 0) + 1;
                }
            }
            \Illuminate\Support\Facades\Log::warning('CSRF419-DEBUG', [
                'path' => $req->path(),
                'host' => $req->getHost(),
                'method' => $req->method(),
                'cookie_names' => $names, // name => count (дубли = коллизия)
                'session_cookie_expected' => config('session.cookie'),
                'has_session_cookie' => $req->cookies->has((string) config('session.cookie')),
                'has_x_csrf' => $req->hasHeader('X-CSRF-TOKEN'),
                'has_x_xsrf' => $req->hasHeader('X-XSRF-TOKEN'),
                'has__token' => $req->has('_token'),
                'is_livewire' => $req->hasHeader('X-Livewire'),
                'content_length' => $req->headers->get('Content-Length'),
                'ua' => substr((string) $req->userAgent(), 0, 70),
            ]);
        });
    })->create();
