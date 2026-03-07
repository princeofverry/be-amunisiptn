<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TryoutController extends Controller
{
    public function index(): JsonResponse
    {
        $tryouts = Tryout::with(['creator', 'tryoutSubtests.subtest'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $tryouts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $tryout = Tryout::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'is_published' => $validated['is_published'] ?? false,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Tryout berhasil dibuat',
            'data' => $tryout,
        ], 201);
    }

    public function show(Tryout $tryout): JsonResponse
    {
        $tryout->load(['creator', 'tryoutSubtests.subtest']);

        return response()->json([
            'data' => $tryout,
        ]);
    }

    public function update(Request $request, Tryout $tryout): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $tryout->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'is_published' => $validated['is_published'] ?? false,
        ]);

        return response()->json([
            'message' => 'Tryout berhasil diupdate',
            'data' => $tryout,
        ]);
    }

    public function destroy(Tryout $tryout): JsonResponse
    {
        $tryout->delete();

        return response()->json([
            'message' => 'Tryout berhasil dihapus',
        ]);
    }
}