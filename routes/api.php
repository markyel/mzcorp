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
