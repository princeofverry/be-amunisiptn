<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionBank;
use App\Models\Tryout;
use App\Models\TryoutQuestion;
use App\Models\TryoutSubtest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TryoutQuestionController extends Controller
{
    public function index(Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data tryout subtest tidak cocok',
            ], 404);
        }

        $cacheKey = "admin_tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions";
        
        $items = Cache::remember($cacheKey, 3600, function() use ($tryoutSubtest) {
            return TryoutQuestion::with(['questionBank.options', 'questionBank.subtest'])
                ->where('tryout_subtest_id', $tryoutSubtest->id)
                ->orderBy('order_no')
                ->orderBy('id')
                ->get();
        });

        return response()->json([
            'data' => $items,
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
            'question_bank_id' => ['required', 'exists:question_bank,id'],
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $questionBank = QuestionBank::findOrFail($validated['question_bank_id']);

        if ($questionBank->subtest_id !== $tryoutSubtest->subtest_id) {
            return response()->json([
                'message' => 'Soal bank tidak cocok dengan subtest tryout ini',
            ], 422);
        }

        $exists = TryoutQuestion::where('tryout_subtest_id', $tryoutSubtest->id)
            ->where('question_bank_id', $questionBank->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Soal bank ini sudah dimasukkan ke tryout',
            ], 422);
        }

        $item = TryoutQuestion::create([
            'tryout_subtest_id' => $tryoutSubtest->id,
            'question_bank_id' => $questionBank->id,
            'order_no' => $validated['order_no'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $item->load(['questionBank.options', 'questionBank.subtest']);
        
        Cache::forget("admin_tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions");
        Cache::forget("tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions");

        return response()->json([
            'message' => 'Soal bank berhasil ditambahkan ke tryout',
            'data' => $item,
        ], 201);
    }

    public function show(Tryout $tryout, TryoutSubtest $tryoutSubtest, TryoutQuestion $tryoutQuestion): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id || $tryoutQuestion->tryout_subtest_id !== $tryoutSubtest->id) {
            return response()->json([
                'message' => 'Data tryout question tidak cocok',
            ], 404);
        }

        $tryoutQuestion->load(['questionBank.options', 'questionBank.subtest']);

        return response()->json([
            'data' => $tryoutQuestion,
        ]);
    }

    public function update(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest, TryoutQuestion $tryoutQuestion): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id || $tryoutQuestion->tryout_subtest_id !== $tryoutSubtest->id) {
            return response()->json([
                'message' => 'Data tryout question tidak cocok',
            ], 404);
        }

        $validated = $request->validate([
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);

        $tryoutQuestion->update($validated);
        $tryoutQuestion->load(['questionBank.options', 'questionBank.subtest']);

        Cache::forget("admin_tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions");
        Cache::forget("tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions");

        return response()->json([
            'message' => 'Soal tryout berhasil diupdate',
            'data' => $tryoutQuestion,
        ]);
    }

    public function destroy(Tryout $tryout, TryoutSubtest $tryoutSubtest, TryoutQuestion $tryoutQuestion): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id || $tryoutQuestion->tryout_subtest_id !== $tryoutSubtest->id) {
            return response()->json([
                'message' => 'Data tryout question tidak cocok',
            ], 404);
        }

        $tryoutQuestion->delete();
        
        Cache::forget("admin_tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions");
        Cache::forget("tryout_{$tryout->id}_subtest_{$tryoutSubtest->id}_questions");

        return response()->json([
            'message' => 'Soal tryout berhasil dihapus',
        ]);
    }
}