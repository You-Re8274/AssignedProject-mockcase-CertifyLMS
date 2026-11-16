<?php

declare(strict_types=1);

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON API のルート定義。
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.read');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.read-all');
    });
