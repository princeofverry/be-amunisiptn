<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Tryout;
use App\Models\TryoutSubtest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    public function index(Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data tryout subtest tidak cocok',
            ], 404);
        }

        $questions = Question::with('options')
            ->where('tryout_subtest_id', $tryoutSubtest->id)
            ->orderBy('order_no')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $questions,
        ]);
    }

    public function store(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data tryout subtest tidak cocok',
            ], 404);
        }

        $validated = $request->validate([
            'question_text' => ['required', 'string'],
            'discussion' => ['nullable', 'string'],
            'correct_answer' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_key' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
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

        $question = DB::transaction(function () use ($validated, $tryoutSubtest) {
            $question = Question::create([
                'tryout_subtest_id' => $tryoutSubtest->id,
                'question_text' => $validated['question_text'],
                'discussion' => $validated['discussion'] ?? null,
                'correct_answer' => $validated['correct_answer'],
                'order_no' => $validated['order_no'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            foreach ($validated['options'] as $option) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'option_key' => $option['option_key'],
                    'option_text' => $option['option_text'],
                ]);
            }

            return $question->load('options');
        });

        return response()->json([
            'message' => 'Soal berhasil dibuat',
            'data' => $question,
        ], 201);
    }

    public function show(Tryout $tryout, TryoutSubtest $tryoutSubtest, Question $question): JsonResponse
    {
        if (
            $tryoutSubtest->tryout_id !== $tryout->id ||
            $question->tryout_subtest_id !== $tryoutSubtest->id
        ) {
            return response()->json([
                'message' => 'Data soal tidak cocok',
            ], 404);
        }

        $question->load('options');

        return response()->json([
            'data' => $question,
        ]);
    }

    public function update(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest, Question $question): JsonResponse
    {
        if (
            $tryoutSubtest->tryout_id !== $tryout->id ||
            $question->tryout_subtest_id !== $tryoutSubtest->id
        ) {
            return response()->json([
                'message' => 'Data soal tidak cocok',
            ], 404);
        }

        $validated = $request->validate([
            'question_text' => ['required', 'string'],
            'discussion' => ['nullable', 'string'],
            'correct_answer' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_key' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
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

        $question = DB::transaction(function () use ($validated, $question) {
            $question->update([
                'question_text' => $validated['question_text'],
                'discussion' => $validated['discussion'] ?? null,
                'correct_answer' => $validated['correct_answer'],
                'order_no' => $validated['order_no'],
                'is_active' => $validated['is_active'],
            ]);

            $question->options()->delete();

            foreach ($validated['options'] as $option) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'option_key' => $option['option_key'],
                    'option_text' => $option['option_text'],
                ]);
            }

            return $question->load('options');
        });

        return response()->json([
            'message' => 'Soal berhasil diupdate',
            'data' => $question,
        ]);
    }

    public function destroy(Tryout $tryout, TryoutSubtest $tryoutSubtest, Question $question): JsonResponse
    {
        if (
            $tryoutSubtest->tryout_id !== $tryout->id ||
            $question->tryout_subtest_id !== $tryoutSubtest->id
        ) {
            return response()->json([
                'message' => 'Data soal tidak cocok',
            ], 404);
        }

        $question->delete();

        return response()->json([
            'message' => 'Soal berhasil dihapus',
        ]);
    }
}