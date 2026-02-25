<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthBootstrapController;
use App\Http\Controllers\AuthRefreshController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AppAssetController;
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
Route::post('/auth/refresh', [AuthRefreshController::class, 'refresh']);

Route::middleware([LocalJwtAuth::class])->group(function () {
    Route::post('/apps/draft', [AppController::class, 'createDraft']);
    Route::delete('/apps/{appId}/draft', [AppController::class, 'deleteDraft'])->whereNumber('appId');
    Route::post('/apps/{appId}/mark-inserted', [AppController::class, 'markInserted'])->whereNumber('appId');
    Route::get('/apps/{appId}/package', [AppController::class, 'package'])->whereNumber('appId');
    Route::get('/apps/{appId}/assets', [AppAssetController::class, 'index'])->whereNumber('appId');
    Route::post('/apps/{appId}/assets/image', [AppAssetController::class, 'uploadImage'])->whereNumber('appId');
    Route::delete('/apps/{appId}/assets/{assetId}', [AppAssetController::class, 'destroy'])->whereNumber('appId');
    Route::get('/apps/{appId}/state', [AppController::class, 'getState'])->whereNumber('appId');
    Route::get('/apps/{appId}/state-summary', [AppController::class, 'getStateSummary'])->whereNumber('appId');
    Route::put('/apps/{appId}/state', [AppController::class, 'setState'])->whereNumber('appId');
    Route::put('/apps/{appId}/save-revision', [AppController::class, 'saveRevision'])->whereNumber('appId');
    Route::delete('/apps/mapping', [AppController::class, 'clearMapping']);
    Route::post('/apps/generate', [GenerateAppController::class, 'generate']);
});
