<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    public function index(Subtest $subtest): JsonResponse
    {
        $questions = Question::with('options')
            ->where('subtest_id', $subtest->id)
            ->orderBy('order_no')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $questions,
        ]);
    }

    public function store(Request $request, Subtest $subtest): JsonResponse
    {
        if ($subtest->max_questions > 0) {
            $currentQuestionCount = Question::where('subtest_id', $subtest->id)->count();
            if ($currentQuestionCount >= $subtest->max_questions) {
                return response()->json([
                    'message' => 'Gagal menambah soal. Kuota maksimal soal (' . $subtest->max_questions . ') sudah terpenuhi.',
                ], 422);
            }
        }

        $validated = $request->validate([
            'question_text' => ['nullable', 'string'],
            'question_image' => ['nullable', 'image', 'max:2048'],
            'discussion' => ['nullable', 'string'],
            'discussion_image' => ['nullable', 'image', 'max:2048'],
            'correct_answer' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_key' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'options.*.option_text' => ['nullable', 'string'],
            'options.*.image' => ['nullable', 'image', 'max:2048'],
        ]);

        $optionKeys = collect($validated['options'])->pluck('option_key');
        if ($optionKeys->count() !== $optionKeys->unique()->count()) {
            return response()->json(['message' => 'option_key tidak boleh duplikat'], 422);
        }

        if (! $optionKeys->contains($validated['correct_answer'])) {
            return response()->json(['message' => 'correct_answer harus ada di dalam options'], 422);
        }

        $question = DB::transaction(function () use ($request, $validated, $subtest) {
            // Upload Gambar Soal & Diskusi
            $qImage = $request->hasFile('question_image') ? $request->file('question_image')->store('question-images', 'public') : null;
            $dImage = $request->hasFile('discussion_image') ? $request->file('discussion_image')->store('discussion-images', 'public') : null;

            $question = Question::create([
                'subtest_id' => $subtest->id,
                'question_text' => $validated['question_text'] ?? null,
                'question_image' => $qImage,
                'discussion' => $validated['discussion'] ?? null,
                'discussion_image' => $dImage,
                'correct_answer' => $validated['correct_answer'],
                'order_no' => $validated['order_no'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // Upload Gambar di Opsi (Jika Ada)
            foreach ($validated['options'] as $index => $option) {
                $optImage = null;
                if ($request->hasFile("options.{$index}.image")) {
                    $optImage = $request->file("options.{$index}.image")->store('option-images', 'public');
                }

                QuestionOption::create([
                    'question_id' => $question->id,
                    'option_key' => $option['option_key'],
                    'option_text' => $option['option_text'] ?? null,
                    'image' => $optImage,
                ]);
            }

            return $question->load('options');
        });

        return response()->json([
            'message' => 'Soal berhasil dibuat',
            'data' => $question,
        ], 201);
    }

    public function show(Subtest $subtest, Question $question): JsonResponse
    {
        if ($question->subtest_id !== $subtest->id) {
            return response()->json(['message' => 'Data soal tidak cocok dengan subtest ini'], 404);
        }

        $question->load('options');

        return response()->json([
            'data' => $question,
        ]);
    }

    public function update(Request $request, Subtest $subtest, Question $question): JsonResponse
    {
        if ($question->subtest_id !== $subtest->id) {
            return response()->json(['message' => 'Data soal tidak cocok dengan subtest ini'], 404);
        }

        $validated = $request->validate([
            'question_text' => ['nullable', 'string'],
            'question_image' => ['nullable', 'image', 'max:2048'],
            'discussion' => ['nullable', 'string'],
            'discussion_image' => ['nullable', 'image', 'max:2048'],
            'correct_answer' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_key' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'options.*.option_text' => ['nullable', 'string'],
            'options.*.image' => ['nullable', 'image', 'max:2048'],
        ]);

        $optionKeys = collect($validated['options'])->pluck('option_key');
        if ($optionKeys->count() !== $optionKeys->unique()->count()) {
            return response()->json(['message' => 'option_key tidak boleh duplikat'], 422);
        }

        if (! $optionKeys->contains($validated['correct_answer'])) {
            return response()->json(['message' => 'correct_answer harus ada di dalam options'], 422);
        }

        $question = DB::transaction(function () use ($request, $validated, $question) {
            $qImage = $question->question_image;
            if ($request->hasFile('question_image')) {
                if ($qImage) Storage::disk('public')->delete($qImage);
                $qImage = $request->file('question_image')->store('question-images', 'public');
            }

            $dImage = $question->discussion_image;
            if ($request->hasFile('discussion_image')) {
                if ($dImage) Storage::disk('public')->delete($dImage);
                $dImage = $request->file('discussion_image')->store('discussion-images', 'public');
            }

            $question->update([
                'question_text' => $validated['question_text'] ?? null,
                'question_image' => $qImage,
                'discussion' => $validated['discussion'] ?? null,
                'discussion_image' => $dImage,
                'correct_answer' => $validated['correct_answer'],
                'order_no' => $validated['order_no'],
                'is_active' => $validated['is_active'],
            ]);

            $oldOptions = $question->options->keyBy('option_key');
            $question->options()->delete();

            foreach ($validated['options'] as $index => $option) {
                $optKey = $option['option_key'];
                $oldImage = $oldOptions->has($optKey) ? $oldOptions[$optKey]->image : null;
                $optImage = $oldImage;

                if ($request->hasFile("options.{$index}.image")) {
                    if ($oldImage) Storage::disk('public')->delete($oldImage);
                    $optImage = $request->file("options.{$index}.image")->store('option-images', 'public');
                }

                QuestionOption::create([
                    'question_id' => $question->id,
                    'option_key' => $optKey,
                    'option_text' => $option['option_text'] ?? null,
                    'image' => $optImage,
                ]);
            }

            return $question->load('options');
        });

        return response()->json([
            'message' => 'Soal berhasil diupdate',
            'data' => $question,
        ]);
    }

    public function destroy(Subtest $subtest, Question $question): JsonResponse
    {
        if ($question->subtest_id !== $subtest->id) {
            return response()->json(['message' => 'Data soal tidak cocok dengan subtest ini'], 404);
        }

        if ($question->question_image) Storage::disk('public')->delete($question->question_image);
        if ($question->discussion_image) Storage::disk('public')->delete($question->discussion_image);
        foreach ($question->options as $opt) {
            if ($opt->image) Storage::disk('public')->delete($opt->image);
        }

        $question->delete();

        return response()->json([
            'message' => 'Soal berhasil dihapus beserta seluruh gambarnya',
        ]);
    }
}