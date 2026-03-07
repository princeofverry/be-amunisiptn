<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionBankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = QuestionBank::with(['subtest', 'options', 'creator'])->latest();

        if ($request->filled('subtest_id')) {
            $query->where('subtest_id', $request->integer('subtest_id'));
        }

        $items = $query->get();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subtest_id' => ['required', 'exists:subtests,id'],
            'question_text' => ['required', 'string'],
            'discussion' => ['nullable', 'string'],
            'correct_answer' => ['required', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'difficulty' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_key' => ['required', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'options.*.option_text' => ['required', 'string'],
        ]);

        $optionKeys = collect($validated['options'])->pluck('option_key');
        if ($optionKeys->count() !== $optionKeys->unique()->count()) {
            return response()->json([
                'message' => 'option_key tidak boleh duplikat',
            ], 422);
        }

        if (! $optionKeys->contains($validated['correct_answer'])) {
            return response()->json([
                'message' => 'correct_answer harus ada di dalam options',
            ], 422);
        }

        $question = DB::transaction(function () use ($validated, $request) {
            $question = QuestionBank::create([
                'subtest_id' => $validated['subtest_id'],
                'question_text' => $validated['question_text'],
                'discussion' => $validated['discussion'] ?? null,
                'correct_answer' => $validated['correct_answer'],
                'difficulty' => $validated['difficulty'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['options'] as $option) {
                QuestionBankOption::create([
                    'question_bank_id' => $question->id,
                    'option_key' => $option['option_key'],
                    'option_text' => $option['option_text'],
                ]);
            }

            return $question->load(['subtest', 'options', 'creator']);
        });

        return response()->json([
            'message' => 'Soal bank berhasil dibuat',
            'data' => $question,
        ], 201);
    }

    public function show(QuestionBank $questionBank): JsonResponse
    {
        $questionBank->load(['subtest', 'options', 'creator']);

        return response()->json([
            'data' => $questionBank,
        ]);
    }

    public function update(Request $request, QuestionBank $questionBank): JsonResponse
    {
        $validated = $request->validate([
            'subtest_id' => ['required', 'exists:subtests,id'],
            'question_text' => ['required', 'string'],
            'discussion' => ['nullable', 'string'],
            'correct_answer' => ['required', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'difficulty' => ['nullable', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_key' => ['required', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'options.*.option_text' => ['required', 'string'],
        ]);

        $optionKeys = collect($validated['options'])->pluck('option_key');
        if ($optionKeys->count() !== $optionKeys->unique()->count()) {
            return response()->json([
                'message' => 'option_key tidak boleh duplikat',
            ], 422);
        }

        if (! $optionKeys->contains($validated['correct_answer'])) {
            return response()->json([
                'message' => 'correct_answer harus ada di dalam options',
            ], 422);
        }

        $questionBank = DB::transaction(function () use ($validated, $questionBank) {
            $questionBank->update([
                'subtest_id' => $validated['subtest_id'],
                'question_text' => $validated['question_text'],
                'discussion' => $validated['discussion'] ?? null,
                'correct_answer' => $validated['correct_answer'],
                'difficulty' => $validated['difficulty'] ?? null,
                'is_active' => $validated['is_active'],
            ]);

            $questionBank->options()->delete();

            foreach ($validated['options'] as $option) {
                QuestionBankOption::create([
                    'question_bank_id' => $questionBank->id,
                    'option_key' => $option['option_key'],
                    'option_text' => $option['option_text'],
                ]);
            }

            return $questionBank->load(['subtest', 'options', 'creator']);
        });

        return response()->json([
            'message' => 'Soal bank berhasil diupdate',
            'data' => $questionBank,
        ]);
    }

    public function destroy(QuestionBank $questionBank): JsonResponse
    {
        $questionBank->delete();

        return response()->json([
            'message' => 'Soal bank berhasil dihapus',
        ]);
    }
}