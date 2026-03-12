<?php

use App\Http\Controllers\Api\AccessCodeController;
use App\Http\Controllers\Api\AdminAccessCodeController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminPackageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PackageCatalogController;
use App\Http\Controllers\Api\PaymentCallbackController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\SubtestController;
use App\Http\Controllers\Api\TryoutController;
use App\Http\Controllers\Api\TryoutSubtestController;
use App\Http\Controllers\Api\UserTryoutController;
use App\Http\Controllers\Api\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::post('/midtrans/callback', [PaymentCallbackController::class, 'handle']);

Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('/register', 'register');
    Route::post('/login', 'login');
    Route::get('/google/redirect', 'redirectToGoogle');
    Route::get('/google/callback', 'handleGoogleCallback');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', 'me');
        Route::post('/logout', 'logout');
    });
});

/*
|--------------------------------------------------------------------------
| Authenticated User Routes (Siswa)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/profile/update', [ProfileController::class, 'update']);
    Route::post('/access-codes/redeem', [AccessCodeController::class, 'redeem']);
    Route::get('/subtests', [SubtestController::class, 'index']);

    // Package & Orders
    Route::apiResource('packages', PackageCatalogController::class)->only(['index', 'show']);
    Route::get('/my-orders', [OrderController::class, 'index']);
    Route::apiResource('orders', OrderController::class)->only(['store', 'show']);

    // --- Ujian & Ujian Tryout (User) ---
    Route::controller(UserTryoutController::class)->group(function () {
        Route::get('/my-tryouts', 'myTryouts');
        
        Route::prefix('tryouts/{tryout}')->group(function () {
            Route::post('/start', 'start');
            Route::post('/finish', 'finish');
            Route::get('/result', 'result');
            Route::get('/review', 'review');
            
            Route::prefix('subtests/{tryoutSubtest}')->group(function () {
                Route::post('/start', 'startSubtest');
                Route::post('/finish', 'finishSubtest');
                Route::get('/exam', 'showSubtestQuestions');
                Route::post('/questions/{question}/answer', 'submitAnswer'); 
            });
        });
    });
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function () {

        Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);

        // --- SUBTEST & MASTER SOAL ---
        Route::apiResource('subtests', SubtestController::class)->except(['index']); 
        Route::apiResource('subtests.questions', QuestionController::class);

        // --- TRYOUT & PENGATURAN TRYOUT ---
        Route::apiResource('tryouts', TryoutController::class);
        Route::apiResource('tryouts.subtests', TryoutSubtestController::class)
            ->parameters(['subtests' => 'tryoutSubtest'])
            ->except(['show']);
        Route::apiResource('tryouts.access-codes', AdminAccessCodeController::class);

        // --- PACKAGES & ORDERS ---
        Route::apiResource('packages', AdminPackageController::class);
        Route::apiResource('orders', AdminOrderController::class)->only(['index', 'show']);
        Route::controller(AdminOrderController::class)->prefix('orders/{order}')->group(function () {
            Route::post('/approve', 'approve');
            Route::post('/reject', 'reject');
        });
    });