<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

class BulkImportQuestionController extends Controller
{
    // Kolom XLSX: Gambar | Soal | Opsi A-E | Kunci Jawaban | Pembahasan
    // Kolom CSV : Soal | Opsi A-E | Penjelasan | Kunci Jawaban  (format lama, tidak berubah)

    private const ALLOWED_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];

    public function store(Request $request, Subtest $subtest): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());

        [$imported, $skipped, $errors] = $ext === 'xlsx'
            ? $this->importFromExcel($file, $subtest)
            : $this->importFromCsv($file, $subtest);

        if ($imported > 0) {
            AuditLogger::log(
                'Question', 'bulk_import',
                "Import {$imported} soal ke subtest \"{$subtest->name}\"" . ($skipped > 0 ? ", {$skipped} baris dilewati" : ''),
                $request->user(), $subtest
            );
        }

        return response()->json([
            'message'  => "{$imported} soal berhasil diimpor." . ($skipped > 0 ? " {$skipped} baris dilewati." : ''),
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ], $imported > 0 ? 201 : 422);
    }

    // -------------------------------------------------------------------------
    // CSV Import (format lama — tidak berubah)
    // Kolom: Soal | Opsi A | Opsi B | Opsi C | Opsi D | Opsi E | Penjelasan | Kunci
    // -------------------------------------------------------------------------
    private function importFromCsv(UploadedFile $file, Subtest $subtest): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return [0, 0, ['File tidak dapat dibaca.']];
        }

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
            return [0, 0, ['File CSV kosong.']];
        }

        $firstCell = trim((string) ($rows[0][0] ?? ''));
        $isHeader  = stripos($firstCell, 'soal') !== false || stripos($firstCell, 'pertanyaan') !== false;
        if ($isHeader) array_shift($rows);

        return $this->processRows($rows, $subtest, $isHeader, fn($row, $lineNo) => [
            'image'         => null,
            'question_text' => trim((string) ($row[0] ?? '')),
            'options'       => [
                'A' => trim((string) ($row[1] ?? '')),
                'B' => trim((string) ($row[2] ?? '')),
                'C' => trim((string) ($row[3] ?? '')),
                'D' => trim((string) ($row[4] ?? '')),
                'E' => trim((string) ($row[5] ?? '')),
            ],
            'discussion'    => trim((string) ($row[6] ?? '')),
            'correct'       => strtoupper(trim((string) ($row[7] ?? ''))),
        ]);
    }

    // -------------------------------------------------------------------------
    // Excel Import (format baru)
    // Kolom: Gambar | Soal | Opsi A | Opsi B | Opsi C | Opsi D | Opsi E | Kunci | Pembahasan
    // -------------------------------------------------------------------------
    private function importFromExcel(UploadedFile $file, Subtest $subtest): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet       = $spreadsheet->getActiveSheet();

        // Bangun map: rowNumber → Drawing (untuk gambar embedded)
        $imageByRow = [];
        foreach ($sheet->getDrawingCollection() as $drawing) {
            preg_match('/(\d+)$/', $drawing->getCoordinates(), $m);
            if (!empty($m[1])) {
                $imageByRow[(int) $m[1]] = $drawing;
            }
        }

        // Ambil semua baris sebagai array (1-indexed)
        $allRows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', 'I') as $cell) {
                $cells[] = trim((string) $cell->getFormattedValue());
            }
            $allRows[$row->getRowIndex()] = $cells;
        }

        if (empty($allRows)) {
            return [0, 0, ['File Excel kosong.']];
        }

        // Deteksi header (row pertama berisi teks header)
        $firstRow  = reset($allRows);
        $firstKey  = array_key_first($allRows);
        $isHeader  = stripos($firstRow[1] ?? '', 'soal') !== false
                  || stripos($firstRow[0] ?? '', 'gambar') !== false;
        if ($isHeader) unset($allRows[$firstKey]);

        $errors   = [];
        $imported = 0;
        $skipped  = 0;
        $maxQ     = $subtest->max_questions;
        $currentQ = Question::where('subtest_id', $subtest->id)->count();
        $startNo  = $currentQ + 1;

        foreach ($allRows as $rowNum => $cells) {
            $lineNo = $rowNum;

            if (empty(array_filter($cells))) continue;

            $questionText  = $cells[1] ?? '';
            $answerA       = $cells[2] ?? '';
            $answerB       = $cells[3] ?? '';
            $answerC       = $cells[4] ?? '';
            $answerD       = $cells[5] ?? '';
            $answerE       = $cells[6] ?? '';
            $correctAnswer = strtoupper($cells[7] ?? '');
            $discussion    = $cells[8] ?? '';

            // Validasi teks
            $rowErrors = [];
            if (empty($questionText)) {
                $rowErrors[] = 'Soal tidak boleh kosong.';
            }
            if (empty($answerA) || empty($answerB) || empty($answerC) || empty($answerD) || empty($answerE)) {
                $rowErrors[] = 'Semua jawaban A-E harus diisi.';
            }
            if (!in_array($correctAnswer, ['A', 'B', 'C', 'D', 'E'])) {
                $rowErrors[] = "Kunci jawaban '{$correctAnswer}' tidak valid.";
            }
            if (!empty($rowErrors)) {
                $errors[] = "Baris {$lineNo}: " . implode(' ', $rowErrors);
                $skipped++;
                continue;
            }

            if ($maxQ > 0 && ($currentQ + $imported) >= $maxQ) {
                $errors[] = "Baris {$lineNo}: Batas maksimal soal ({$maxQ}) tercapai.";
                $skipped++;
                continue;
            }

            // Ekstrak gambar jika ada di baris ini
            $imagePath = null;
            if (isset($imageByRow[$rowNum])) {
                $imagePath = $this->extractAndStoreImage($imageByRow[$rowNum], $lineNo, $errors);
            }

            $orderNo = $startNo + $imported;
            DB::transaction(function () use ($subtest, $questionText, $answerA, $answerB, $answerC, $answerD, $answerE, $discussion, $correctAnswer, $imagePath, $orderNo) {
                $question = Question::create([
                    'subtest_id'     => $subtest->id,
                    'question_text'  => $questionText,
                    'question_image' => $imagePath,
                    'discussion'     => $discussion ?: null,
                    'correct_answer' => $correctAnswer,
                    'order_no'       => $orderNo,
                    'is_active'      => true,
                ]);

                foreach (['A' => $answerA, 'B' => $answerB, 'C' => $answerC, 'D' => $answerD, 'E' => $answerE] as $key => $text) {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'option_key'  => $key,
                        'option_text' => $text,
                    ]);
                }
            });

            $imported++;
        }

        return [$imported, $skipped, $errors];
    }

    // -------------------------------------------------------------------------
    // Ekstrak satu gambar dari Drawing object → simpan ke storage/public
    // Mengembalikan relative path (untuk disimpan ke DB) atau null jika gagal
    // -------------------------------------------------------------------------
    private function extractAndStoreImage(Drawing|MemoryDrawing $drawing, int $lineNo, array &$errors): ?string
    {
        try {
            if ($drawing instanceof MemoryDrawing) {
                $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $ext     = $mimeMap[$drawing->getMimeType()] ?? null;

                if (!$ext) {
                    $errors[] = "Baris {$lineNo}: Format gambar tidak didukung.";
                    return null;
                }

                ob_start();
                $renderFunc = $drawing->getRenderingFunction();
                $renderFunc($drawing->getImageResource());
                $content = ob_get_clean();
            } else {
                $path = $drawing->getPath(); // zip://...xlsx#xl/media/imageN.jpg
                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                if (!in_array($ext, self::ALLOWED_IMAGE_EXTS)) {
                    $errors[] = "Baris {$lineNo}: Format gambar '{$ext}' tidak didukung.";
                    return null;
                }

                $content = file_get_contents($path);
            }

            if (empty($content)) {
                $errors[] = "Baris {$lineNo}: Gambar kosong, dilewati.";
                return null;
            }

            $storagePath = 'questions/' . Str::ulid() . '.' . $ext;
            Storage::disk('public')->put($storagePath, $content);

            return $storagePath;
        } catch (\Throwable $e) {
            $errors[] = "Baris {$lineNo}: Gagal memproses gambar ({$e->getMessage()}).";
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Helper: iterasi baris + insert soal (shared antara CSV & Excel jika perlu)
    // -------------------------------------------------------------------------
    private function processRows(array $rows, Subtest $subtest, bool $isHeader, callable $mapper): array
    {
        $errors   = [];
        $imported = 0;
        $skipped  = 0;
        $maxQ     = $subtest->max_questions;
        $currentQ = Question::where('subtest_id', $subtest->id)->count();
        $startNo  = $currentQ + 1;

        foreach ($rows as $rowIndex => $row) {
            $lineNo = $rowIndex + ($isHeader ? 2 : 1);

            if (empty(array_filter($row))) continue;

            $data = $mapper($row, $lineNo);

            $rowErrors = [];
            if (empty($data['question_text'])) {
                $rowErrors[] = 'Soal tidak boleh kosong.';
            }
            foreach ($data['options'] as $key => $val) {
                if (empty($val)) {
                    $rowErrors[] = "Jawaban {$key} tidak boleh kosong.";
                }
            }
            if (!in_array($data['correct'], ['A', 'B', 'C', 'D', 'E'])) {
                $rowErrors[] = "Kunci jawaban '{$data['correct']}' tidak valid.";
            }

            if (!empty($rowErrors)) {
                $errors[] = "Baris {$lineNo}: " . implode(' ', $rowErrors);
                $skipped++;
                continue;
            }

            if ($maxQ > 0 && ($currentQ + $imported) >= $maxQ) {
                $errors[] = "Baris {$lineNo}: Batas maksimal soal ({$maxQ}) tercapai.";
                $skipped++;
                continue;
            }

            $orderNo = $startNo + $imported;
            DB::transaction(function () use ($subtest, $data, $orderNo) {
                $question = Question::create([
                    'subtest_id'     => $subtest->id,
                    'question_text'  => $data['question_text'],
                    'question_image' => $data['image'],
                    'discussion'     => $data['discussion'] ?: null,
                    'correct_answer' => $data['correct'],
                    'order_no'       => $orderNo,
                    'is_active'      => true,
                ]);

                foreach ($data['options'] as $key => $text) {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'option_key'  => $key,
                        'option_text' => $text,
                    ]);
                }
            });

            $imported++;
        }

        return [$imported, $skipped, $errors];
    }

    // -------------------------------------------------------------------------
    // Download template CSV (format lama)
    // -------------------------------------------------------------------------
    public function template(): \Illuminate\Http\Response
    {
        $rows = [
            ['Soal', 'Jawaban A', 'Jawaban B', 'Jawaban C', 'Jawaban D', 'Jawaban E', 'Penjelasan', 'Kunci Jawaban (A/B/C/D/E)'],
            ['Berapakah nilai dari 2 + 2?', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Operasi penjumlahan dasar: 2 + 2 = 4', 'B'],
        ];

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) fputcsv($handle, $row);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-soal-amunisi.csv"',
        ]);
    }

    // -------------------------------------------------------------------------
    // Download template Excel (format baru dengan kolom Gambar)
    // -------------------------------------------------------------------------
    public function excelTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template Soal');

        $headers = ['Gambar', 'Soal', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Kunci Jawaban', 'Pembahasan'];
        $sheet->fromArray($headers, null, 'A1');

        // Style header
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF004AAB']],
        ]);

        // Contoh data di row 2
        $sheet->fromArray([
            '',                                    // Gambar — isi dengan embed gambar di cell ini
            'Berapakah nilai dari 2 + 2?',
            'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh',
            'B',
            'Operasi penjumlahan dasar: 2 + 2 = 4',
        ], null, 'A2');

        // Instruksi di row 4
        $sheet->setCellValue('A4', 'Catatan:');
        $sheet->setCellValue('A5', '- Kolom Gambar: embed gambar langsung ke cell (Insert → Pictures → Place in Cell)');
        $sheet->setCellValue('A6', '- Kunci Jawaban hanya boleh: A, B, C, D, atau E');
        $sheet->setCellValue('A7', '- Baris pertama adalah header, data mulai dari baris 2');
        $sheet->setCellValue('A8', '- Format gambar yang didukung: jpg, jpeg, png, webp');
        $sheet->getStyle('A4:A8')->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF666666'));

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('B')->setWidth(50); // kolom Soal lebih lebar
        $sheet->getRowDimension(2)->setRowHeight(30);

        $writer = new Xlsx($spreadsheet);

        return response()->stream(
            fn() => $writer->save('php://output'),
            200,
            [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="template-soal-amunisi.xlsx"',
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]
        );
    }
}
