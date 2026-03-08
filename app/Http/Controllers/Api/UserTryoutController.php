<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Models\TryoutQuestion;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\TryoutSubtestSession;
use App\Models\UserAnswer;
use App\Models\UserTryoutAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserTryoutController extends Controller
{
    public function myTryouts(Request $request): JsonResponse
    {
        $user = $request->user();

        $tryoutIds = UserTryoutAccess::where('user_id', $user->id)
            ->pluck('tryout_id');

        $tryouts = Tryout::with(['tryoutSubtests.subtest'])
            ->whereIn('id', $tryoutIds)
            ->where('is_published', true)
            ->get();

        return response()->json([
            'data' => $tryouts,
        ]);
    }

    public function start(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $hasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'Kamu tidak punya akses ke tryout ini',
            ], 403);
        }

        $session = TryoutSession::firstOrCreate(
            [
                'user_id' => $user->id,
                'tryout_id' => $tryout->id,
            ],
            [
                'started_at' => now(),
                'status' => 'in_progress',
            ]
        );

        if ($session->status === 'not_started') {
            $session->update([
                'started_at' => now(),
                'status' => 'in_progress',
            ]);

            $session->refresh();
        }

        return response()->json([
            'message' => 'Tryout dimulai',
            'data' => $session,
        ]);
    }

    public function startSubtest(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        $user = $request->user();

        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data tryout subtest tidak cocok',
            ], 404);
        }

        $hasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'Kamu tidak punya akses ke tryout ini',
            ], 403);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Tryout belum dimulai',
            ], 422);
        }

        if ($session->status === 'finished') {
            return response()->json([
                'message' => 'Tryout sudah selesai',
            ], 422);
        }

        $subtestSession = TryoutSubtestSession::firstOrCreate(
            [
                'tryout_session_id' => $session->id,
                'tryout_subtest_id' => $tryoutSubtest->id,
            ],
            [
                'started_at' => now(),
                'status' => 'in_progress',
            ]
        );

        $endTime = $subtestSession->started_at
            ? $subtestSession->started_at->copy()->addMinutes($tryoutSubtest->duration_minutes)
            : null;

        $remainingSeconds = $endTime
            ? max(now()->diffInSeconds($endTime, false), 0)
            : 0;

        if ($remainingSeconds <= 0 && $subtestSession->status === 'in_progress') {
            $subtestSession->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);

            $subtestSession->refresh();
        }

        return response()->json([
            'message' => 'Subtest dimulai',
            'data' => [
                'subtest_session_id' => $subtestSession->id,
                'started_at' => $subtestSession->started_at,
                'end_time' => $endTime,
                'remaining_seconds' => $remainingSeconds,
                'status' => $subtestSession->status,
            ],
        ]);
    }

    public function showSubtestQuestions(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        $user = $request->user();

        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data tryout subtest tidak cocok',
            ], 404);
        }

        $hasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'Kamu tidak punya akses ke tryout ini',
            ], 403);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Tryout belum dimulai',
            ], 422);
        }

        if ($session->status === 'finished') {
            return response()->json([
                'message' => 'Tryout sudah selesai',
            ], 422);
        }

        $subtestSession = TryoutSubtestSession::where('tryout_session_id', $session->id)
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->first();

        if (! $subtestSession) {
            return response()->json([
                'message' => 'Subtest belum dimulai',
            ], 422);
        }

        $endTime = $subtestSession->started_at
            ? $subtestSession->started_at->copy()->addMinutes($tryoutSubtest->duration_minutes)
            : null;

        $remainingSeconds = $endTime
            ? max(now()->diffInSeconds($endTime, false), 0)
            : 0;

        if ($remainingSeconds <= 0 && $subtestSession->status === 'in_progress') {
            $subtestSession->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);

            $subtestSession->refresh();

            return response()->json([
                'message' => 'Waktu subtest sudah habis',
                'data' => [
                    'timer' => [
                        'started_at' => $subtestSession->started_at,
                        'end_time' => $endTime,
                        'remaining_seconds' => 0,
                        'status' => $subtestSession->status,
                    ],
                ],
            ], 422);
        }

        $questions = TryoutQuestion::with(['questionBank.options'])
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->where('is_active', true)
            ->orderBy('order_no')
            ->orderBy('id')
            ->get()
            ->map(function ($item) use ($session) {
                $question = $item->questionBank;

                $userAnswer = UserAnswer::where('tryout_session_id', $session->id)
                    ->where('tryout_question_id', $item->id)
                    ->first();

                return [
                    'id' => $item->id,
                    'question_bank_id' => $question->id,
                    'question_text' => $question->question_text,
                    'order_no' => $item->order_no,
                    'options' => $question->options->map(function ($option) {
                        return [
                            'id' => $option->id,
                            'option_key' => $option->option_key,
                            'option_text' => $option->option_text,
                        ];
                    })->values(),
                    'my_answer' => $userAnswer?->answer,
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'tryout' => [
                    'id' => $tryout->id,
                    'title' => $tryout->title,
                ],
                'subtest' => [
                    'id' => $tryoutSubtest->id,
                    'name' => $tryoutSubtest->subtest->name,
                    'duration_minutes' => $tryoutSubtest->duration_minutes,
                ],
                'timer' => [
                    'started_at' => $subtestSession->started_at,
                    'end_time' => $endTime,
                    'remaining_seconds' => $remainingSeconds,
                    'status' => $subtestSession->status,
                ],
                'questions' => $questions,
            ],
        ]);
    }

    public function submitAnswer(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest, TryoutQuestion $tryoutQuestion): JsonResponse
    {
        $user = $request->user();

        if (
            $tryoutSubtest->tryout_id !== $tryout->id ||
            $tryoutQuestion->tryout_subtest_id !== $tryoutSubtest->id
        ) {
            return response()->json([
                'message' => 'Data soal tidak cocok',
            ], 404);
        }

        $validated = $request->validate([
            'answer' => ['nullable', 'string', 'in:A,B,C,D,E'],
        ]);

        $hasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'Kamu tidak punya akses ke tryout ini',
            ], 403);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Tryout belum dimulai',
            ], 422);
        }

        if ($session->status === 'finished') {
            return response()->json([
                'message' => 'Tryout sudah selesai',
            ], 422);
        }

        $subtestSession = TryoutSubtestSession::where('tryout_session_id', $session->id)
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->first();

        if (! $subtestSession) {
            return response()->json([
                'message' => 'Subtest belum dimulai',
            ], 422);
        }

        $endTime = $subtestSession->started_at
            ? $subtestSession->started_at->copy()->addMinutes($tryoutSubtest->duration_minutes)
            : null;

        $remainingSeconds = $endTime
            ? max(now()->diffInSeconds($endTime, false), 0)
            : 0;

        if ($remainingSeconds <= 0) {
            $subtestSession->update([
                'status' => 'expired',
                'expired_at' => $subtestSession->expired_at ?? now(),
            ]);

            $subtestSession->refresh();

            return response()->json([
                'message' => 'Waktu subtest sudah habis',
                'data' => [
                    'timer' => [
                        'started_at' => $subtestSession->started_at,
                        'end_time' => $endTime,
                        'remaining_seconds' => 0,
                        'status' => $subtestSession->status,
                    ],
                ],
            ], 422);
        }

        $answer = $validated['answer'] ?? null;
        $correctAnswer = $tryoutQuestion->questionBank->correct_answer;

        $userAnswer = UserAnswer::updateOrCreate(
            [
                'tryout_session_id' => $session->id,
                'tryout_question_id' => $tryoutQuestion->id,
            ],
            [
                'answer' => $answer,
                'is_correct' => $answer ? $answer === $correctAnswer : null,
                'answered_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Jawaban berhasil disimpan',
            'data' => $userAnswer,
        ]);
    }

    public function finish(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Tryout belum dimulai',
            ], 422);
        }

        if ($session->status === 'finished') {
            return response()->json([
                'message' => 'Tryout sudah selesai',
                'data' => $session,
            ]);
        }

        $session->update([
            'status' => 'finished',
            'finished_at' => now(),
        ]);

        return response()->json([
            'message' => 'Tryout selesai',
            'data' => $session->fresh(),
        ]);
    }

    public function result(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $session = TryoutSession::with(['answers'])
            ->where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Session tryout tidak ditemukan',
            ], 404);
        }

        $totalQuestions = TryoutQuestion::whereHas('tryoutSubtest', function ($query) use ($tryout) {
            $query->where('tryout_id', $tryout->id);
        })->where('is_active', true)->count();

        $answered = $session->answers()->whereNotNull('answer')->count();
        $correct = $session->answers()->where('is_correct', true)->count();
        $wrong = $session->answers()->where('is_correct', false)->count();
        $unanswered = max($totalQuestions - $answered, 0);

        return response()->json([
            'data' => [
                'tryout_id' => $tryout->id,
                'tryout_title' => $tryout->title,
                'status' => $session->status,
                'started_at' => $session->started_at,
                'finished_at' => $session->finished_at,
                'summary' => [
                    'total_questions' => $totalQuestions,
                    'answered' => $answered,
                    'correct' => $correct,
                    'wrong' => $wrong,
                    'unanswered' => $unanswered,
                ],
            ],
        ]);
    }
}