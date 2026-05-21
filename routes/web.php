<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\OAuthYandexController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Yandex 360 OAuth flow для подключения почтовых ящиков (XOAUTH2).
    Route::get('/oauth/yandex/authorize', [OAuthYandexController::class, 'authorize'])
        ->name('oauth.yandex.authorize');
    Route::get('/oauth/yandex/callback', [OAuthYandexController::class, 'callback'])
        ->name('oauth.yandex.callback');

    // Заявки — пул менеджера и карточка. Все 4 роли;
    // фильтрация «своё/всё» — внутри Pool component.
    Route::middleware('role:manager,head_of_sales,director,secretary,admin')->group(function () {
        Route::get('/dashboard/requests', function () {
            return view('requests.index');
        })->name('requests.index');

        Route::get('/dashboard/requests/{request}', function (\App\Models\Request $request) {
            return view('requests.show', ['request' => $request]);
        })->name('requests.show');

        Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])
            ->name('attachments.download');
        Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'preview'])
            ->name('attachments.preview');
        Route::get('/attachments/cid/{emailMessage}/{contentId}', [AttachmentController::class, 'inline'])
            ->where('contentId', '.*')
            ->name('attachments.inline');

        // КП (наши исходящие) — preview + download PDF. Permission проверяется
        // в контроллере (owner/acting/privileged).
        Route::get('/dashboard/quotations/{quotation}/preview',
            [\App\Http\Controllers\QuotationPdfController::class, 'preview'])
            ->name('quotations.preview');
        Route::get('/dashboard/quotations/{quotation}/download',
            [\App\Http\Controllers\QuotationPdfController::class, 'download'])
            ->name('quotations.download');

        // Standalone-поиск по каталогу (без привязки к заявке) —
        // тот же hybrid pipeline (code + trgm + vector), что в
        // ItemCatalogLinkDialog «Похожие из каталога». Доступен всем
        // авторизованным ролям как справочник.
        Route::get('/dashboard/catalog/search', function () {
            return view('catalog.search');
        })->name('catalog.search');
    });

    // Mail routing rules — управление правилами для РОП и директора.
    Route::middleware('role:head_of_sales,director,admin')->group(function () {
        Route::get('/dashboard/mail-rules', function () {
            return view('admin.mail-rules.index');
        })->name('mail-rules.index');

        Route::get('/dashboard/mail-rules/create', function () {
            return view('admin.mail-rules.edit', ['rule' => new \App\Models\MailRoutingRule()]);
        })->name('mail-rules.create');

        Route::get('/dashboard/mail-rules/{rule}/edit', function (\App\Models\MailRoutingRule $rule) {
            return view('admin.mail-rules.edit', ['rule' => $rule]);
        })->name('mail-rules.edit');

        // Управление менеджерами (Phase 1.13) — список + create/edit + OAuth-привязка ящиков.
        Route::get('/dashboard/managers', function () {
            return view('admin.managers.index');
        })->name('managers.index');

        Route::get('/dashboard/managers/create', function () {
            return view('admin.managers.edit', ['user' => new \App\Models\User()]);
        })->name('managers.create');

        Route::get('/dashboard/managers/{user}/edit', function (\App\Models\User $user) {
            return view('admin.managers.edit', ['user' => $user]);
        })->name('managers.edit');

        // Phase 2: настройки приложения (catalog matching, тарифы и т.п.).
        // Override поверх config(), редактируется через Livewire-страницу.
        Route::get('/dashboard/settings', function () {
            return view('admin.settings.index');
        })->name('settings.index');

        // Foundation Фаза 2: auto-rejection — РОП пересматривает письма,
        // которые AI классифицировал как irrelevant/reclamation/accounting/...,
        // и реоткрывает ошибочно отклонённые как Request.
        Route::get('/dashboard/mail-review', function () {
            return view('admin.mail-review.index');
        })->name('mail-review.index');
    });

    // Общие почтовые ящики (mail@myzip.ru, info@myzip.ru и т.п.) —
    // подключение, OAuth/app-password, активация/деактивация
    // распределения заявок. Доступно ТОЛЬКО админу: критично, что
    // ни директор, ни РОП не могут случайно отключить основную почту.
    Route::middleware('role:admin')->group(function () {
        Route::get('/dashboard/mailboxes', function () {
            return view('admin.mailboxes.index');
        })->name('mailboxes.index');
    });
});

require __DIR__.'/auth.php';
