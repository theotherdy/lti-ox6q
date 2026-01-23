<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthBootstrapController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\GenerateAppController;
use App\Http\Middleware\LocalJwtAuth;

Route::get('/health', function () {
    return response()->json(['ok' => true]);
});

// Preflight responder (Laravel's HandleCors may also handle this)
Route::options('/{any}', function () {
    return response('', 204);
})->where('any', '.*');

Route::post('/auth/bootstrap', [AuthBootstrapController::class, 'bootstrap']);

Route::middleware([LocalJwtAuth::class])->group(function () {
    Route::get('/apps/{appId}/package', [AppController::class, 'package']);
    Route::get('/apps/{appId}/state', [AppController::class, 'getState']);
    Route::put('/apps/{appId}/state', [AppController::class, 'setState']);
    Route::post('/apps/generate', [GenerateAppController::class, 'generate']);
});
