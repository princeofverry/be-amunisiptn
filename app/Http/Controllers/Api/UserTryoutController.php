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
use Illuminate\Support\Facades\Cache; // Redis sudah kita hapus dari import ini

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

        // Acak urutan Subtest untuk setiap Tryout secara konsisten per User
        $tryouts->each(function ($tryout) use ($user) {
            $shuffledSubtests = $tryout->tryoutSubtests->sortBy(function ($subtest) use ($user) {
                return md5($user->id . $subtest->id);
            })->values();
            
            $shuffledSubtests->each(function($subtest, $index) {
                $subtest->order_no = $index + 1;
            });

            $tryout->setRelation('tryoutSubtests', $shuffledSubtests);
        });

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

        $cacheKey = "tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions";
        $questionsData = Cache::remember($cacheKey, 3600, function() use ($tryoutSubtest) {
            return TryoutQuestion::with(['questionBank.options'])
                ->where('tryout_subtest_id', $tryoutSubtest->id)
                ->where('is_active', true)
                ->get();
        });

        $questionsData = $questionsData->sortBy(function ($item) use ($session) {
            return md5($session->id . $item->id);
        })->values();
        $userAnswers = UserAnswer::where('tryout_session_id', $session->id)
            ->pluck('answer', 'tryout_question_id');

        $questions = $questionsData->map(function ($item, $index) use ($userAnswers, $session) {
            $question = $item->questionBank;
            $myAnswer = $userAnswers[$item->id] ?? null;
            
            $shuffledOptions = $question->options->sortBy(function ($option) use ($session, $item) {
                return md5($session->id . $item->id . $option->id);
            })->values();
            
            return [
                'id' => $item->id,
                'question_bank_id' => $question->id,
                'question_text' => $question->question_text,
                'question_image' => $question->question_image,
                'question_image_url' => $question->question_image_url,
                'order_no' => $index + 1,
                'options' => $shuffledOptions->map(function ($option) {
                    return [
                        'id' => $option->id,
                            'option_key' => $option->option_key,
                            'option_text' => $option->option_text,
                        ];
                    })->values(),
                    'my_answer' => $myAnswer,
                ];
        })->values();

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

        if ($answer) {
            $questionBank = $tryoutQuestion->questionBank;
            $correctAnswer = $questionBank->correct_answer ?? null;

            UserAnswer::updateOrCreate(
                [
                    'tryout_session_id' => $session->id,
                    'tryout_question_id' => $tryoutQuestion->id,
                ],
                [
                    'answer' => $answer,
                    'is_correct' => $answer === $correctAnswer,
                    'answered_at' => now(),
                ]
            );
        } else {
            UserAnswer::where('tryout_session_id', $session->id)
                ->where('tryout_question_id', $tryoutQuestion->id)
                ->delete();
        }

        return response()->json([
            'message' => 'Jawaban berhasil disimpan',
            'data' => [
                'tryout_question_id' => $tryoutQuestion->id,
                'answer' => $answer
            ],
        ]);
    }

    public function finishSubtest(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        $user = $request->user();

        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data tryout subtest tidak cocok',
            ], 404);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Tryout belum dimulai',
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

        if (in_array($subtestSession->status, ['finished', 'expired'])) {
            return response()->json([
                'message' => 'Subtest ini sudah selesai sebelumnya',
                'data' => $subtestSession,
            ]);
        }

        $subtestSession->update([
            'status' => 'finished',
            'finished_at' => now(),
        ]);

        return response()->json([
            'message' => 'Subtest berhasil diselesaikan',
            'data' => $subtestSession->fresh(),
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

        $totalParticipants = TryoutSession::where('tryout_id', $tryout->id)
            ->where('status', 'finished')
            ->count();

        $rawIrtScore = 0;
        $finalScore1000 = 0;

        if ($totalParticipants > 0) {
            $allTryoutQuestions = TryoutQuestion::whereHas('tryoutSubtest', function ($query) use ($tryout) {
                $query->where('tryout_id', $tryout->id);
            })->where('is_active', true)->get();

            $totalWeightAll = 0;
            $questionStats = [];

            foreach ($allTryoutQuestions as $q) {
                $correctCount = UserAnswer::where('tryout_question_id', $q->id)
                    ->where('is_correct', true)
                    ->count();

                $p = $correctCount / $totalParticipants;
                $safeP = $p <= 0 ? 0.0001 : ($p >= 1 ? 0.9999 : $p);
                $weight = max(1, log((1 - $safeP) / $safeP) + 2); 

                $questionStats[$q->id] = $weight;
                $totalWeightAll += $weight;
            }

            foreach ($session->answers as $answer) {
                if ($answer->is_correct && isset($questionStats[$answer->tryout_question_id])) {
                    $rawIrtScore += $questionStats[$answer->tryout_question_id];
                }
            }

            $finalScore1000 = ($totalWeightAll > 0) ? ($rawIrtScore / $totalWeightAll) * 1000 : 0;
        }

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
                'irt_result' => [
                    'total_participants_calculated' => $totalParticipants,
                    'raw_score' => round($rawIrtScore, 2),
                    'final_score' => round($finalScore1000, 2),
                ]
            ],
        ]);
    }

    public function review(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Session tryout tidak ditemukan',
            ], 404);
        }

        if ($session->status !== 'finished') {
            return response()->json([
                'message' => 'Review hanya bisa diakses setelah tryout selesai',
            ], 422);
        }

        $questions = TryoutQuestion::with([
                'questionBank.options',
                'tryoutSubtest.subtest',
            ])
            ->whereHas('tryoutSubtest', function ($query) use ($tryout) {
                $query->where('tryout_id', $tryout->id);
            })
            ->where('is_active', true)
            ->orderBy('order_no')
            ->get();

        $userAnswers = UserAnswer::where('tryout_session_id', $session->id)
            ->get()
            ->keyBy('tryout_question_id');

        $data = $questions->map(function ($item) use ($userAnswers) {
            $question = $item->questionBank;
            $answer = $userAnswers->get($item->id);

            return [
                'tryout_question_id' => $item->id,
                'subtest' => [
                    'id' => $item->tryoutSubtest->subtest->id,
                    'name' => $item->tryoutSubtest->subtest->name,
                ],
                'question' => [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_image' => $question->question_image,
                    'question_image_url' => $question->question_image_url,
                    'discussion' => $question->discussion,
                    'discussion_image' => $question->discussion_image,
                    'discussion_image_url' => $question->discussion_image_url,
                    'correct_answer' => $question->correct_answer,
                    'options' => $question->options->map(function ($option) {
                        return [
                            'id' => $option->id,
                            'option_key' => $option->option_key,
                            'option_text' => $option->option_text,
                        ];
                    })->values(),
                ],
                'my_answer' => $answer?->answer,
                'is_correct' => $answer?->is_correct,
            ];
        })->values();

        return response()->json([
            'data' => [
                'tryout_id' => $tryout->id,
                'tryout_title' => $tryout->title,
                'review' => $data,
            ],
        ]);
    }
}