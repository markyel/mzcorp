<?php

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
    // Доступ должен быть только у admin/РОП — gates добавим в Phase 1.5.
    Route::get('/oauth/yandex/authorize', [OAuthYandexController::class, 'authorize'])
        ->name('oauth.yandex.authorize');
    Route::get('/oauth/yandex/callback', [OAuthYandexController::class, 'callback'])
        ->name('oauth.yandex.callback');
});

require __DIR__.'/auth.php';
