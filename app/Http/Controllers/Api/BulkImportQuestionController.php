<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BulkImportQuestionController extends Controller
{
    /**
     * Format kolom CSV:
     * Kolom 1 : Soal (question_text)
     * Kolom 2 : Jawaban A
     * Kolom 3 : Jawaban B
     * Kolom 4 : Jawaban C
     * Kolom 5 : Jawaban D
     * Kolom 6 : Jawaban E
     * Kolom 7 : Penjelasan (discussion)
     * Kolom 8 : Kunci Jawaban (A/B/C/D/E)
     */
    public function store(Request $request, Subtest $subtest): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $file = $request->file('file');

        // Baca CSV dengan native PHP — tidak butuh library eksternal
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return response()->json(['message' => 'File tidak dapat dibaca.'], 422);
        }

        // Hilangkan BOM UTF-8 jika ada
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        if (empty($rows)) {
            return response()->json(['message' => 'File CSV kosong.'], 422);
        }

        // Hapus baris pertama jika header
        $firstCell = trim((string)($rows[0][0] ?? ''));
        $isHeader  = stripos($firstCell, 'soal') !== false || stripos($firstCell, 'pertanyaan') !== false;
        if ($isHeader) {
            array_shift($rows);
        }

        $errors   = [];
        $imported = 0;
        $skipped  = 0;

        // Cek batas max soal
        $maxQ       = $subtest->max_questions;
        $currentQ   = Question::where('subtest_id', $subtest->id)->count();
        $startOrderNo = $currentQ + 1;

        foreach ($rows as $rowIndex => $row) {
            $lineNo = $rowIndex + ($isHeader ? 2 : 1);

            // Skip baris kosong
            if (empty(array_filter($row))) {
                continue;
            }

            $questionText  = trim((string)($row[0] ?? ''));
            $answerA       = trim((string)($row[1] ?? ''));
            $answerB       = trim((string)($row[2] ?? ''));
            $answerC       = trim((string)($row[3] ?? ''));
            $answerD       = trim((string)($row[4] ?? ''));
            $answerE       = trim((string)($row[5] ?? ''));
            $discussion    = trim((string)($row[6] ?? ''));
            $correctAnswer = strtoupper(trim((string)($row[7] ?? '')));

            // Validasi per baris
            $rowErrors = [];

            if (empty($questionText)) {
                $rowErrors[] = 'Soal tidak boleh kosong.';
            }
            if (empty($answerA) || empty($answerB) || empty($answerC) || empty($answerD) || empty($answerE)) {
                $rowErrors[] = 'Semua jawaban A-E harus diisi.';
            }
            if (!in_array($correctAnswer, ['A', 'B', 'C', 'D', 'E'])) {
                $rowErrors[] = "Kunci jawaban '$correctAnswer' tidak valid. Harus A, B, C, D, atau E.";
            }

            if (!empty($rowErrors)) {
                $errors[] = "Baris {$lineNo}: " . implode(' ', $rowErrors);
                $skipped++;
                continue;
            }

            // Cek batas max soal
            if ($maxQ > 0 && ($currentQ + $imported) >= $maxQ) {
                $errors[] = "Baris {$lineNo}: Batas maksimal soal ({$maxQ}) sudah tercapai. Baris selanjutnya dilewati.";
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($subtest, $questionText, $answerA, $answerB, $answerC, $answerD, $answerE, $discussion, $correctAnswer, $startOrderNo, $imported) {
                $question = Question::create([
                    'subtest_id'     => $subtest->id,
                    'question_text'  => $questionText,
                    'discussion'     => $discussion ?: null,
                    'correct_answer' => $correctAnswer,
                    'order_no'       => $startOrderNo + $imported,
                    'is_active'      => true,
                ]);

                $options = [
                    'A' => $answerA,
                    'B' => $answerB,
                    'C' => $answerC,
                    'D' => $answerD,
                    'E' => $answerE,
                ];

                foreach ($options as $key => $text) {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'option_key'  => $key,
                        'option_text' => $text,
                    ]);
                }
            });

            $imported++;
        }

        if ($imported > 0) {
            AuditLogger::log('Question', 'bulk_import', "Import {$imported} soal ke subtest \"{$subtest->name}\"" . ($skipped > 0 ? ", {$skipped} baris dilewati" : ''), $request->user(), $subtest);
        }

        return response()->json([
            'message'  => "{$imported} soal berhasil diimpor." . ($skipped > 0 ? " {$skipped} baris dilewati." : ''),
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ], $imported > 0 ? 201 : 422);
    }

    /**
     * Download template sebagai CSV — tidak butuh library eksternal,
     * Excel bisa membukanya langsung dan endpoint import sudah support .csv
     */
    public function template(): \Illuminate\Http\Response
    {
        $rows = [
            ['Soal', 'Jawaban A', 'Jawaban B', 'Jawaban C', 'Jawaban D', 'Jawaban E', 'Penjelasan', 'Kunci Jawaban (A/B/C/D/E)'],
            ['Berapakah nilai dari 2 + 2?', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Operasi penjumlahan dasar: 2 + 2 = 4', 'B'],
        ];

        $handle = fopen('php://temp', 'r+');
        // BOM agar Excel buka dengan encoding UTF-8 yang benar
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-soal-amunisi.csv"',
        ]);
    }
}
