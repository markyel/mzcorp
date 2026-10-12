<?php

use App\Http\Controllers\Api\CatalogImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Минимальный набор JSON-эндпоинтов для серверных интеграций. На
| момент Phase 2 — только приёмник snapshot'ов каталога из MDB.
| Auth — bearer-token через middleware CatalogImportToken.
|
*/

Route::post('/catalog/import', CatalogImportController::class)
    ->middleware('catalog.import.token')
    ->name('api.catalog.import');

// Боты заявок в Telegram и MAX: проверка секрета в заголовке — в контроллере.
Route::post('/bot/telegram', [\App\Http\Controllers\Api\BotWebhookController::class, 'telegram'])
    ->middleware('throttle:600,1')
    ->name('api.bot.telegram');
Route::post('/bot/max', [\App\Http\Controllers\Api\BotWebhookController::class, 'max'])
    ->middleware('throttle:600,1')
    ->name('api.bot.max');
