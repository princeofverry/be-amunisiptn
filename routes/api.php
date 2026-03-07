<?php

use App\Http\Controllers\Api\AccessCodeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SubtestController;
use App\Http\Controllers\Api\TryoutController;
use App\Http\Controllers\Api\TryoutSubtestController;
use App\Http\Controllers\Api\QuestionController;
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

    Route::get('/tryouts', [TryoutController::class, 'index']);
    Route::post('/tryouts', [TryoutController::class, 'store']);
    Route::get('/tryouts/{tryout}', [TryoutController::class, 'show']);
    Route::put('/tryouts/{tryout}', [TryoutController::class, 'update']);
    Route::delete('/tryouts/{tryout}', [TryoutController::class, 'destroy']);

    Route::post('/tryouts/{tryout}/subtests', [TryoutSubtestController::class, 'store']);
    Route::put('/tryouts/{tryout}/subtests/{tryoutSubtest}', [TryoutSubtestController::class, 'update']);
    Route::delete('/tryouts/{tryout}/subtests/{tryoutSubtest}', [TryoutSubtestController::class, 'destroy']);

    Route::get('/tryouts/{tryout}/subtests/{tryoutSubtest}/questions', [QuestionController::class, 'index']);
    Route::post('/tryouts/{tryout}/subtests/{tryoutSubtest}/questions', [QuestionController::class, 'store']);
    Route::get('/tryouts/{tryout}/subtests/{tryoutSubtest}/questions/{question}', [QuestionController::class, 'show']);
    Route::put('/tryouts/{tryout}/subtests/{tryoutSubtest}/questions/{question}', [QuestionController::class, 'update']);
    Route::delete('/tryouts/{tryout}/subtests/{tryoutSubtest}/questions/{question}', [QuestionController::class, 'destroy']);
});