<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Models\TryoutQuestion;
use App\Models\UserAnswer;
use App\Models\TryoutSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IRTScoreController extends Controller
{
    /**
     * Menghitung skor IRT untuk sesi tryout tertentu.
     * Alur:
     * 1. Hitung tingkat kesulitan tiap soal (Difficulty Index).
     * 2. Hitung bobot tiap soal berdasarkan logaritma probabilitas.
     * 3. Kalkulasi skor user berdasarkan soal yang dijawab benar.
     */
    public function calculateIRTScore($tryoutId, $sessionId)
    {
        // 1. Ambil semua soal dalam tryout ini
        $questions = TryoutQuestion::where('tryout_id', $tryoutId)->get();
        
        // 2. Ambil statistik jawaban untuk semua peserta di tryout ini (untuk menentukan bobot)
        $totalParticipants = TryoutSession::where('tryout_id', $tryoutId)
            ->where('status', 'completed')
            ->count();

        if ($totalParticipants === 0) {
            return response()->json(['message' => 'Belum ada data peserta yang selesai.'], 400);
        }

        $questionStats = [];

        foreach ($questions as $q) {
            $correctAnswersCount = UserAnswer::where('question_id', $q->question_id)
                ->where('is_correct', true)
                ->count();

            $p = $correctAnswersCount / $totalParticipants;

            /**
             * Rumus IRT Sederhana (Weighting):
             * Soal semakin sulit (p kecil) -> Bobot semakin besar.
             * Kita gunakan Logit Scale atau inverse dari p.
             * Jika p = 0 (tidak ada yang benar), kita beri nilai kecil (0.0001) agar tidak pembagian nol.
             */
            $safeP = $p <= 0 ? 0.0001 : ($p >= 1 ? 0.9999 : $p);
            
            $weight = log((1 - $safeP) / $safeP);

            $questionStats[$q->question_id] = [
                'correct_count' => $correctAnswersCount,
                'difficulty_index' => $p,
                'weight' => max(1, $weight + 2)
            ];
        }

        // 3. Hitung skor untuk user spesifik pada sesi ini
        $userAnswers = UserAnswer::where('tryout_session_id', $sessionId)->get();
        $totalUserScore = 0;
        $maxPossibleScore = 0;

        $details = [];

        foreach ($userAnswers as $answer) {
            $weight = $questionStats[$answer->question_id]['weight'];
            $isCorrect = $answer->is_correct;
            
            $scoreObtained = $isCorrect ? $weight : 0;
            $totalUserScore += $scoreObtained;

            $details[] = [
                'question_id' => $answer->question_id,
                'is_correct' => $isCorrect,
                'weight' => round($weight, 2),
                'score' => round($scoreObtained, 2)
            ];
        }

        // 4. Hitung nilai akhir dalam skala 0 - 1000 (seperti UTBK)
        $totalWeightAll = array_sum(array_column($questionStats, 'weight'));
        $finalScore = ($totalWeightAll > 0) ? ($totalUserScore / $totalWeightAll) * 1000 : 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                'session_id' => $sessionId,
                'total_participants_base' => $totalParticipants,
                'raw_irt_score' => round($totalUserScore, 2),
                'final_score_1000' => round($finalScore, 2),
                'breakdown' => $details
            ]
        ]);
    }
}