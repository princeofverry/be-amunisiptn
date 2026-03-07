<?php

use App\Http\Controllers\Api\AccessCodeController;
use App\Http\Controllers\Api\AdminAccessCodeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\QuestionBankController;
use App\Http\Controllers\Api\SubtestController;
use App\Http\Controllers\Api\TryoutController;
use App\Http\Controllers\Api\TryoutQuestionController;
use App\Http\Controllers\Api\TryoutSubtestController;
use App\Http\Controllers\Api\UserTryoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
| Public:
| - register
| - login
|
| Protected:
| - me
| - logout
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| USER / PESERTA
|--------------------------------------------------------------------------
| Dipakai user biasa yang sudah login:
| - redeem code
| - lihat subtest master
| - lihat tryout yang dia punya akses
| - start tryout
| - ambil soal exam
| - submit jawaban
| - finish tryout
| - lihat hasil
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/access-codes/redeem', [AccessCodeController::class, 'redeem']);
    Route::get('/subtests', [SubtestController::class, 'index']);

    Route::get('/my-tryouts', [UserTryoutController::class, 'myTryouts']);
    Route::post('/tryouts/{tryout}/start', [UserTryoutController::class, 'start']);
    Route::get('/tryouts/{tryout}/subtests/{tryoutSubtest}/exam', [UserTryoutController::class, 'showSubtestQuestions']);
    Route::post('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions/{tryoutQuestion}/answer', [UserTryoutController::class, 'submitAnswer']);
    Route::post('/tryouts/{tryout}/finish', [UserTryoutController::class, 'finish']);
    Route::get('/tryouts/{tryout}/result', [UserTryoutController::class, 'result']);
});

/*
|--------------------------------------------------------------------------
| ADMIN
|--------------------------------------------------------------------------
| Dipakai admin:
| - CRUD subtest
| - CRUD tryout
| - atur subtest di dalam tryout
| - CRUD access code per tryout
| - CRUD bank soal
| - assign bank soal ke tryout subtest
*/
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | MASTER SUBTEST
    |--------------------------------------------------------------------------
    */
    Route::post('/subtests', [SubtestController::class, 'store']);
    Route::put('/subtests/{subtest}', [SubtestController::class, 'update']);
    Route::delete('/subtests/{subtest}', [SubtestController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ACCESS CODE PER TRYOUT
    |--------------------------------------------------------------------------
    */
    Route::get('/tryouts/{tryout}/access-codes', [AdminAccessCodeController::class, 'index']);
    Route::post('/tryouts/{tryout}/access-codes', [AdminAccessCodeController::class, 'store']);
    Route::get('/tryouts/{tryout}/access-codes/{accessCode}', [AdminAccessCodeController::class, 'show']);
    Route::put('/tryouts/{tryout}/access-codes/{accessCode}', [AdminAccessCodeController::class, 'update']);
    Route::delete('/tryouts/{tryout}/access-codes/{accessCode}', [AdminAccessCodeController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | TRYOUT
    |--------------------------------------------------------------------------
    */
    Route::get('/tryouts', [TryoutController::class, 'index']);
    Route::post('/tryouts', [TryoutController::class, 'store']);
    Route::get('/tryouts/{tryout}', [TryoutController::class, 'show']);
    Route::put('/tryouts/{tryout}', [TryoutController::class, 'update']);
    Route::delete('/tryouts/{tryout}', [TryoutController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | SUBTEST DI DALAM TRYOUT
    |--------------------------------------------------------------------------
    */
    Route::post('/tryouts/{tryout}/subtests', [TryoutSubtestController::class, 'store']);
    Route::put('/tryouts/{tryout}/subtests/{tryoutSubtest}', [TryoutSubtestController::class, 'update']);
    Route::delete('/tryouts/{tryout}/subtests/{tryoutSubtest}', [TryoutSubtestController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | BANK SOAL
    |--------------------------------------------------------------------------
    | Master soal + opsi + jawaban benar + pembahasan
    */
    Route::get('/question-bank', [QuestionBankController::class, 'index']);
    Route::post('/question-bank', [QuestionBankController::class, 'store']);
    Route::get('/question-bank/{questionBank}', [QuestionBankController::class, 'show']);
    Route::put('/question-bank/{questionBank}', [QuestionBankController::class, 'update']);
    Route::delete('/question-bank/{questionBank}', [QuestionBankController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | SOAL TRYOUT (AMBIL DARI BANK SOAL)
    |--------------------------------------------------------------------------
    | Menempelkan soal bank ke subtest tertentu di tryout
    */
    Route::get('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions', [TryoutQuestionController::class, 'index']);
    Route::post('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions', [TryoutQuestionController::class, 'store']);
    Route::get('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions/{tryoutQuestion}', [TryoutQuestionController::class, 'show']);
    Route::put('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions/{tryoutQuestion}', [TryoutQuestionController::class, 'update']);
    Route::delete('/tryouts/{tryout}/subtests/{tryoutSubtest}/bank-questions/{tryoutQuestion}', [TryoutQuestionController::class, 'destroy']);
});