<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
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
        }

        return response()->json([
            'message' => 'Tryout dimulai',
            'data' => $session,
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

        $questions = Question::with(['options'])
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->where('is_active', true)
            ->orderBy('order_no')
            ->orderBy('id')
            ->get()
            ->map(function ($question) use ($session) {
                $userAnswer = UserAnswer::where('tryout_session_id', $session->id)
                    ->where('question_id', $question->id)
                    ->first();

                return [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'order_no' => $question->order_no,
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
                'questions' => $questions,
            ],
        ]);
    }

    public function submitAnswer(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest, Question $question): JsonResponse
    {
        $user = $request->user();

        if (
            $tryoutSubtest->tryout_id !== $tryout->id ||
            $question->tryout_subtest_id !== $tryoutSubtest->id
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

        $answer = $validated['answer'] ?? null;

        $userAnswer = UserAnswer::updateOrCreate(
            [
                'tryout_session_id' => $session->id,
                'question_id' => $question->id,
            ],
            [
                'answer' => $answer,
                'is_correct' => $answer ? $answer === $question->correct_answer : null,
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
            'data' => $session,
        ]);
    }

    public function result(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $session = TryoutSession::with(['answers.question'])
            ->where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Session tryout tidak ditemukan',
            ], 404);
        }

        $totalQuestions = Question::whereHas('tryoutSubtest', function ($query) use ($tryout) {
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