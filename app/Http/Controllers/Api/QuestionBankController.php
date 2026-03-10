<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionBankController extends Controller
{
    // Helper Method for Image
    private function storeImage($file, string $folder): ?string
    {
        if (! $file) {
            return null;
        }

        return $file->store($folder, 'public');
    }

    private function deleteImageIfExists(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

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
            'question_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        
            'discussion' => ['nullable', 'string'],
            'discussion_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        
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
            $questionImagePath = $this->storeImage(
                $request->file('question_image'),
                'question-bank/questions'
            );
        
            $discussionImagePath = $this->storeImage(
                $request->file('discussion_image'),
                'question-bank/discussions'
            );
        
            $question = QuestionBank::create([
                'subtest_id' => $validated['subtest_id'],
                'question_text' => $validated['question_text'],
                'question_image' => $questionImagePath,
                'discussion' => $validated['discussion'] ?? null,
                'discussion_image' => $discussionImagePath,
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
            'question_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_question_image' => ['nullable', 'boolean'],
        
            'discussion' => ['nullable', 'string'],
            'discussion_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_discussion_image' => ['nullable', 'boolean'],
        
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

        $questionBank = DB::transaction(function () use ($validated, $request, $questionBank) {
            $questionImagePath = $questionBank->question_image;
            $discussionImagePath = $questionBank->discussion_image;
        
            if ($request->boolean('remove_question_image')) {
                $this->deleteImageIfExists($questionImagePath);
                $questionImagePath = null;
            }
        
            if ($request->hasFile('question_image')) {
                $this->deleteImageIfExists($questionImagePath);
                $questionImagePath = $this->storeImage(
                    $request->file('question_image'),
                    'question-bank/questions'
                );
            }
        
            if ($request->boolean('remove_discussion_image')) {
                $this->deleteImageIfExists($discussionImagePath);
                $discussionImagePath = null;
            }
        
            if ($request->hasFile('discussion_image')) {
                $this->deleteImageIfExists($discussionImagePath);
                $discussionImagePath = $this->storeImage(
                    $request->file('discussion_image'),
                    'question-bank/discussions'
                );
            }
        
            $questionBank->update([
                'subtest_id' => $validated['subtest_id'],
                'question_text' => $validated['question_text'],
                'question_image' => $questionImagePath,
                'discussion' => $validated['discussion'] ?? null,
                'discussion_image' => $discussionImagePath,
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
        $this->deleteImageIfExists($questionBank->question_image);
        $this->deleteImageIfExists($questionBank->discussion_image);
    
        $questionBank->delete();
    
        return response()->json([
            'message' => 'Soal bank berhasil dihapus',
        ]);
    }
}