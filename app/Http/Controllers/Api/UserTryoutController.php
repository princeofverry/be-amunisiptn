<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Models\Question;
use App\Models\TryoutSession;
use App\Models\TryoutSubtest;
use App\Models\TryoutSubtestSession;
use App\Models\UserAnswer;
use App\Models\UserTryoutAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserTryoutController extends Controller
{
    public function enroll(Request $request, Tryout $tryout): JsonResponse
    {
        $user = $request->user();

        if (!$tryout->is_published) {
            return response()->json(['message' => 'Tryout ini tidak tersedia'], 404);
        }

        if (UserTryoutAccess::where('user_id', $user->id)->where('tryout_id', $tryout->id)->exists()) {
            return response()->json(['message' => 'Kamu sudah terdaftar di tryout ini'], 422);
        }

        // --- JIKA TRYOUT GRATIS ---
        if ($tryout->is_free) {
            
            $validator = Validator::make($request->all(), [
                'proof_image' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048']
            ], [
                'proof_image.required' => 'Bukti follow sosial media wajib diunggah untuk mengikuti tryout gratis.',
                'proof_image.image' => 'Bukti harus berupa gambar.',
                'proof_image.mimes' => 'Format gambar harus jpeg, png, jpg, atau webp.',
                'proof_image.max' => 'Ukuran gambar maksimal 2MB.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $proofPath = $request->file('proof_image')->store('proof-images', 'public');

            UserTryoutAccess::create([
                'user_id' => $user->id,
                'tryout_id' => $tryout->id,
                'proof_image' => $proofPath,
                'granted_at' => now(),
            ]);

            return response()->json([
                'message' => 'Berhasil mendaftar tryout gratis.',
            ]);
        }
        
        // --- JIKA TRYOUT PREMIUM ---
        else {
            if ($user->ticket_balance <= 0) {
                return response()->json(['message' => 'Tiket tidak cukup. Silakan beli paket tiket terlebih dahulu.'], 403);
            }

            DB::transaction(function () use ($user, $tryout) {
                $user->decrement('ticket_balance', 1);

                UserTryoutAccess::create([
                    'user_id' => $user->id,
                    'tryout_id' => $tryout->id,
                    'granted_at' => now(),
                ]);
            });

            return response()->json([
                'message' => 'Berhasil mendaftar tryout. 1 Tiket telah digunakan.',
                'ticket_balance_remaining' => $user->ticket_balance // tidak perlu dikurangi manual krn user di model db sudah terupdate
            ]);
        }
    }

    public function myTryouts(Request $request): JsonResponse
    {
        $user = $request->user();

        $tryoutIds = UserTryoutAccess::where('user_id', $user->id)
            ->pluck('tryout_id');

        $tryouts = Tryout::with(['tryoutSubtests.subtest'])
            ->whereIn('id', $tryoutIds)
            ->where('is_published', true)
            ->get();

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
                'message' => 'Kamu tidak punya akses ke tryout ini. Silakan daftar menggunakan tiket.',
            ], 403);
        }

        $now = now();
        if ($tryout->start_date && $now->lt($tryout->start_date)) {
            return response()->json([
                'message' => 'Tryout belum dimulai. Akan dimulai pada: ' . $tryout->start_date->format('d M Y H:i')
            ], 422);
        }

        if ($tryout->end_date && $now->gt($tryout->end_date)) {
            return response()->json([
                'message' => 'Waktu tryout sudah berakhir.'
            ], 422);
        }

        $existingSession = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if ($existingSession && $existingSession->status === 'finished') {
            return response()->json([
                'message' => 'Kamu sudah menyelesaikan tryout ini dan tidak bisa mengikutinya lagi.',
            ], 422);
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
            return response()->json(['message' => 'Data tryout subtest tidak cocok'], 404);
        }

        $hasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json(['message' => 'Kamu tidak punya akses ke tryout ini'], 403);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Tryout belum dimulai'], 422);
        }

        if ($session->status === 'finished') {
            return response()->json(['message' => 'Tryout sudah selesai'], 422);
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
            return response()->json(['message' => 'Data tryout subtest tidak cocok'], 404);
        }

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Tryout belum dimulai'], 422);
        }

        if ($session->status === 'finished') {
            return response()->json(['message' => 'Tryout sudah selesai'], 422);
        }

        $subtestSession = TryoutSubtestSession::where('tryout_session_id', $session->id)
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->first();

        if (! $subtestSession) {
            return response()->json(['message' => 'Subtest belum dimulai'], 422);
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
            // Menggunakan Question yang terhubung ke subtest_id
            return Question::with(['options'])
                ->where('subtest_id', $tryoutSubtest->subtest_id)
                ->where('is_active', true)
                ->get();
        });

        $questionsData = $questionsData->sortBy(function ($item) use ($session) {
            return md5($session->id . $item->id);
        })->values();
        
        $userAnswers = UserAnswer::where('tryout_session_id', $session->id)
            ->pluck('answer', 'question_id');

        $questions = $questionsData->map(function ($question, $index) use ($userAnswers, $session) {
            $myAnswer = $userAnswers[$question->id] ?? null;
            
            $shuffledOptions = $question->options->sortBy(function ($option) use ($session, $question) {
                return md5($session->id . $question->id . $option->id);
            })->values();
            
            return [
                'id' => $question->id,
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

    public function submitAnswer(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest, Question $question): JsonResponse
    {
        $user = $request->user();

        if (
            $tryoutSubtest->tryout_id !== $tryout->id ||
            $question->subtest_id !== $tryoutSubtest->subtest_id
        ) {
            return response()->json(['message' => 'Data soal tidak cocok'], 404);
        }

        $validated = $request->validate([
            'answer' => ['nullable', 'string', 'in:A,B,C,D,E'],
        ]);

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        if (! $session || $session->status === 'finished') {
            return response()->json(['message' => 'Sesi tidak valid'], 422);
        }

        $answer = $validated['answer'] ?? null;

        if ($answer) {
            $correctAnswer = $question->correct_answer ?? null;

            UserAnswer::updateOrCreate(
                [
                    'tryout_session_id' => $session->id,
                    'question_id' => $question->id,
                ],
                [
                    'answer' => $answer,
                    'is_correct' => $answer === $correctAnswer,
                    'answered_at' => now(),
                ]
            );
        } else {
            UserAnswer::where('tryout_session_id', $session->id)
                ->where('question_id', $question->id)
                ->delete();
        }

        return response()->json([
            'message' => 'Jawaban berhasil disimpan',
            'data' => [
                'question_id' => $question->id,
                'answer' => $answer
            ],
        ]);
    }

    public function finishSubtest(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        $user = $request->user();

        $session = TryoutSession::where('user_id', $user->id)
            ->where('tryout_id', $tryout->id)
            ->first();

        $subtestSession = TryoutSubtestSession::where('tryout_session_id', $session->id ?? '')
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->first();

        if (! $subtestSession) return response()->json(['message' => 'Subtest belum dimulai'], 422);

        if (!in_array($subtestSession->status, ['finished', 'expired'])) {
            $subtestSession->update([
                'status' => 'finished',
                'finished_at' => now(),
            ]);
        }

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

        if (! $session) return response()->json(['message' => 'Tryout belum dimulai'], 422);

        if ($session->status !== 'finished') {
            $session->update([
                'status' => 'finished',
                'finished_at' => now(),
            ]);
        }

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
            return response()->json(['message' => 'Session tryout tidak ditemukan'], 404);
        }

        // Cari subtest apa saja yang ada di Tryout ini
        $subtestIds = TryoutSubtest::where('tryout_id', $tryout->id)->pluck('subtest_id');

        $totalQuestions = Question::whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->count();

        $answered = $session->answers()->whereNotNull('answer')->count();
        $correct = $session->answers()->where('is_correct', true)->count();
        $wrong = $session->answers()->where('is_correct', false)->count();
        $unanswered = max($totalQuestions - $answered, 0);

        $now = now();
        $isIrtReady = true;

        if ($tryout->end_date && $now->lt($tryout->end_date)) {
            $isIrtReady = false;
        }

        $rawIrtScore = 0;
        $finalScore1000 = 0;
        $totalParticipants = TryoutSession::where('tryout_id', $tryout->id)
            ->where('status', 'finished')
            ->count();

        if ($isIrtReady && $totalParticipants > 0) {
            $allTryoutQuestions = Question::whereIn('subtest_id', $subtestIds)
                ->where('is_active', true)
                ->get();

            $totalWeightAll = 0;
            $questionStats = [];

            foreach ($allTryoutQuestions as $q) {
                $correctCount = UserAnswer::where('question_id', $q->id)
                    ->where('is_correct', true)
                    ->count();

                $p = $correctCount / $totalParticipants;
                $safeP = $p <= 0 ? 0.0001 : ($p >= 1 ? 0.9999 : $p);
                $weight = max(1, log((1 - $safeP) / $safeP) + 2); 

                $questionStats[$q->id] = $weight;
                $totalWeightAll += $weight;
            }

            foreach ($session->answers as $answer) {
                if ($answer->is_correct && isset($questionStats[$answer->question_id])) {
                    $rawIrtScore += $questionStats[$answer->question_id];
                }
            }

            $finalScore1000 = ($totalWeightAll > 0) ? ($rawIrtScore / $totalWeightAll) * 1000 : 0;
        }

        return response()->json([
            'message' => !$isIrtReady ? 'Hasil IRT sedang dalam proses dan akan keluar setelah periode tryout berakhir.' : 'Sukses mengambil data IRT',
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
                    'is_ready' => $isIrtReady,
                    'release_date' => $tryout->end_date,
                    'total_participants_calculated' => $isIrtReady ? $totalParticipants : 0,
                    'raw_score' => $isIrtReady ? round($rawIrtScore, 2) : 0,
                    'final_score' => $isIrtReady ? round($finalScore1000, 2) : 0,
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
            return response()->json(['message' => 'Session tryout tidak ditemukan'], 404);
        }

        if ($session->status !== 'finished') {
            return response()->json(['message' => 'Review hanya bisa diakses setelah tryout selesai'], 422);
        }

        $subtestIds = TryoutSubtest::where('tryout_id', $tryout->id)->pluck('subtest_id');

        $questions = Question::with(['options', 'subtest'])
            ->whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->orderBy('order_no')
            ->get();

        $userAnswers = UserAnswer::where('tryout_session_id', $session->id)
            ->get()
            ->keyBy('question_id');

        $data = $questions->map(function ($question) use ($userAnswers, $tryout) {
            $answer = $userAnswers->get($question->id);

            return [
                'question_id' => $question->id,
                'subtest' => [
                    'id' => $question->subtest->id,
                    'name' => $question->subtest->name,
                ],
                'question' => [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_image' => $question->question_image,
                    'question_image_url' => $question->question_image_url,
                    
                    'discussion' => $tryout->is_free ? null : $question->discussion,
                    'discussion_image' => $tryout->is_free ? null : $question->discussion_image,
                    'discussion_image_url' => $tryout->is_free ? null : $question->discussion_image_url,
                    
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