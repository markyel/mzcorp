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
        // Отключённый (архивный) сотрудник выходит на первом же запросе.
        $middleware->web(append: [\App\Http\Middleware\LogoutArchivedUser::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Временный сбой Яндекса (NO [UNAVAILABLE], обрыв на входе) задача переживает повтором — WARNING, не ERROR.
        // Отказ во входе и прочие ошибки — ERROR как раньше; провал после всех повторов — ERROR (AppServiceProvider, JobFailed).
        $exceptions->report(function (\Throwable $e) {
            $cause = \App\Exceptions\Mail\TransientImapException::yandexOutage($e);
            if ($cause === null) {
                return;
            }
            \Illuminate\Support\Facades\Log::warning('IMAP: transient Yandex failure, the job will retry', [
                'cause' => $cause,
                'exception' => $e::class,
                // Самый внешний класс приложения в стеке — задача (SyncMailboxFolderJob…), а не MailboxConnector.
                'at' => collect($e->getTrace())->last(fn (array $f) => str_starts_with($f['class'] ?? '', 'App\\'))['class'] ?? null,
            ]);

            return false;
        });
    })->create();
