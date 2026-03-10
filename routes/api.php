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
use App\Http\Controllers\Api\QuestionBankController;
use App\Http\Controllers\Api\SubtestController;
use App\Http\Controllers\Api\TryoutController;
use App\Http\Controllers\Api\TryoutQuestionController;
use App\Http\Controllers\Api\TryoutSubtestController;
use App\Http\Controllers\Api\UserTryoutController;
use App\Http\Controllers\Api\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::post('/midtrans/callback', [PaymentCallbackController::class, 'handle']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::get('/google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback', [AuthController::class, 'handleGoogleCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/profile/update', [ProfileController::class, 'update']);

    Route::post('/access-codes/redeem', [AccessCodeController::class, 'redeem']);
    Route::get('/subtests', [SubtestController::class, 'index']);

    Route::get('/my-tryouts', [UserTryoutController::class, 'myTryouts']);
    Route::post('/tryouts/{tryout}/start', [UserTryoutController::class, 'start']);
    Route::get('/tryouts/{tryout}/subtests/{tryoutSubtest}/exam', [UserTryoutController::class, 'showSubtestQuestions']);
    Route::post('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions/{tryoutQuestion}/answer', [UserTryoutController::class, 'submitAnswer']);
    Route::post('/tryouts/{tryout}/finish', [UserTryoutController::class, 'finish']);
    Route::get('/tryouts/{tryout}/result', [UserTryoutController::class, 'result']);
    Route::post('/tryouts/{tryout}/subtests/{tryoutSubtest}/start', [UserTryoutController::class, 'startSubtest']);
    Route::post('/tryouts/{tryout}/subtests/{tryoutSubtest}/finish', [UserTryoutController::class, 'finishSubtest']);

    Route::get('/packages', [PackageCatalogController::class, 'index']);
    Route::get('/packages/{package}', [PackageCatalogController::class, 'show']);

    Route::get('/my-orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    // review to show the answer
    Route::get('/tryouts/{tryout}/review', [UserTryoutController::class, 'review']);
});

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function () {

        Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);

        Route::post('/subtests', [SubtestController::class, 'store']);
        Route::put('/subtests/{subtest}', [SubtestController::class, 'update']);
        Route::delete('/subtests/{subtest}', [SubtestController::class, 'destroy']);

        Route::get('/tryouts/{tryout}/access-codes', [AdminAccessCodeController::class, 'index']);
        Route::post('/tryouts/{tryout}/access-codes', [AdminAccessCodeController::class, 'store']);
        Route::get('/tryouts/{tryout}/access-codes/{accessCode}', [AdminAccessCodeController::class, 'show']);
        Route::put('/tryouts/{tryout}/access-codes/{accessCode}', [AdminAccessCodeController::class, 'update']);
        Route::delete('/tryouts/{tryout}/access-codes/{accessCode}', [AdminAccessCodeController::class, 'destroy']);

        Route::get('/tryouts', [TryoutController::class, 'index']);
        Route::post('/tryouts', [TryoutController::class, 'store']);
        Route::get('/tryouts/{tryout}', [TryoutController::class, 'show']);
        Route::put('/tryouts/{tryout}', [TryoutController::class, 'update']);
        Route::delete('/tryouts/{tryout}', [TryoutController::class, 'destroy']);

        Route::get('/tryouts/{tryout}/subtests', [TryoutSubtestController::class, 'index']);
        Route::post('/tryouts/{tryout}/subtests', [TryoutSubtestController::class, 'store']);
        Route::put('/tryouts/{tryout}/subtests/{tryoutSubtest}', [TryoutSubtestController::class, 'update']);
        Route::delete('/tryouts/{tryout}/subtests/{tryoutSubtest}', [TryoutSubtestController::class, 'destroy']);

        Route::get('/question-bank', [QuestionBankController::class, 'index']);
        Route::post('/question-bank', [QuestionBankController::class, 'store']);
        Route::get('/question-bank/{questionBank}', [QuestionBankController::class, 'show']);
        Route::put('/question-bank/{questionBank}', [QuestionBankController::class, 'update']);
        Route::delete('/question-bank/{questionBank}', [QuestionBankController::class, 'destroy']);

        Route::get('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions', [TryoutQuestionController::class, 'index']);
        Route::post('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions', [TryoutQuestionController::class, 'store']);
        Route::get('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions/{tryoutQuestion}', [TryoutQuestionController::class, 'show']);
        Route::put('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions/{tryoutQuestion}', [TryoutQuestionController::class, 'update']);
        Route::delete('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions/{tryoutQuestion}', [TryoutQuestionController::class, 'destroy']);

        Route::get('/packages', [AdminPackageController::class, 'index']);
        Route::post('/packages', [AdminPackageController::class, 'store']);
        Route::get('/packages/{package}', [AdminPackageController::class, 'show']);
        Route::put('/packages/{package}', [AdminPackageController::class, 'update']);
        Route::delete('/packages/{package}', [AdminPackageController::class, 'destroy']);

        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::post('/orders/{order}/approve', [AdminOrderController::class, 'approve']);
        Route::post('/orders/{order}/reject', [AdminOrderController::class, 'reject']);
    });