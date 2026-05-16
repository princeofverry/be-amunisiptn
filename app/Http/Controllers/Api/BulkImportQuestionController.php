<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BulkImportQuestionController extends Controller
{
    /**
     * Format kolom Excel:
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
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $file = $request->file('file');

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Exception $e) {
            return response()->json(['message' => 'File tidak dapat dibaca. Pastikan format file benar (xlsx/xls/csv).'], 422);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, false);

        // Hapus baris pertama jika header
        $firstRow = array_values($rows[0] ?? []);
        $isHeader = is_string($firstRow[0] ?? null) &&
                    stripos((string)($firstRow[0] ?? ''), 'soal') !== false;
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

        return response()->json([
            'message'  => "{$imported} soal berhasil diimpor." . ($skipped > 0 ? " {$skipped} baris dilewati." : ''),
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ], $imported > 0 ? 201 : 422);
    }

    /**
     * Download template Excel kosong
     */
    public function template(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template Soal');

        // Header
        $headers = ['Soal', 'Jawaban A', 'Jawaban B', 'Jawaban C', 'Jawaban D', 'Jawaban E', 'Penjelasan', 'Kunci Jawaban (A/B/C/D/E)'];
        foreach ($headers as $col => $label) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $label);
        }

        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '004AAB']],
        ];
        $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);

        // Auto width kolom
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Contoh baris
        $example = [
            'Berapakah nilai dari 2 + 2?',
            'Tiga',
            'Empat',
            'Lima',
            'Enam',
            'Tujuh',
            'Operasi penjumlahan dasar: 2 + 2 = 4',
            'B',
        ];
        foreach ($example as $col => $val) {
            $sheet->setCellValueByColumnAndRow($col + 1, 2, $val);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $path   = sys_get_temp_dir() . '/template-soal-amunisi.xlsx';
        $writer->save($path);

        return response()->download($path, 'template-soal-amunisi.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
