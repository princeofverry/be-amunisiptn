<?php

use App\Http\Controllers\Api\AccessCodeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SubtestController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/access-codes/redeem', [AccessCodeController::class, 'redeem']);
    Route::get('/subtests', [SubtestController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/subtests', [SubtestController::class, 'store']);
    Route::put('/subtests/{subtest}', [SubtestController::class, 'update']);
    Route::delete('/subtests/{subtest}', [SubtestController::class, 'destroy']);
});