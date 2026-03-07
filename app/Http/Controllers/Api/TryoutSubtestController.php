<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Models\TryoutSubtest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TryoutSubtestController extends Controller
{
    public function store(Request $request, Tryout $tryout): JsonResponse
    {
        $validated = $request->validate([
            'subtest_id' => ['required', 'exists:subtests,id'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $exists = TryoutSubtest::where('tryout_id', $tryout->id)
            ->where('subtest_id', $validated['subtest_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Subtest ini sudah ada di tryout tersebut',
            ], 422);
        }

        $item = TryoutSubtest::create([
            'tryout_id' => $tryout->id,
            'subtest_id' => $validated['subtest_id'],
            'duration_minutes' => $validated['duration_minutes'],
            'order_no' => $validated['order_no'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $item->load('subtest');

        return response()->json([
            'message' => 'Subtest berhasil ditambahkan ke tryout',
            'data' => $item,
        ], 201);
    }

    public function update(Request $request, Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data subtest tryout tidak cocok',
            ], 404);
        }

        $validated = $request->validate([
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'order_no' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);

        $tryoutSubtest->update($validated);
        $tryoutSubtest->load('subtest');

        return response()->json([
            'message' => 'Pengaturan subtest tryout berhasil diupdate',
            'data' => $tryoutSubtest,
        ]);
    }

    public function destroy(Tryout $tryout, TryoutSubtest $tryoutSubtest): JsonResponse
    {
        if ($tryoutSubtest->tryout_id !== $tryout->id) {
            return response()->json([
                'message' => 'Data subtest tryout tidak cocok',
            ], 404);
        }

        $tryoutSubtest->delete();

        return response()->json([
            'message' => 'Subtest berhasil dihapus dari tryout',
        ]);
    }
}