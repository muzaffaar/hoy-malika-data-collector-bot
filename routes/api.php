<?php

use App\Http\Controllers\DatasetSyncController;
use App\Http\Middleware\SyncToken;
use Illuminate\Support\Facades\Route;

Route::prefix('dataset-sync')->middleware([SyncToken::class, 'throttle:120,1'])->group(function () {
    Route::get('pending', [DatasetSyncController::class, 'pending']);
    Route::get('deletions', [DatasetSyncController::class, 'deletions']);
    Route::get('{recording}/download', [DatasetSyncController::class, 'download']);
    Route::post('{recording}/complete', [DatasetSyncController::class, 'complete']);
    Route::post('{recording}/deleted', [DatasetSyncController::class, 'deleted']);
});
