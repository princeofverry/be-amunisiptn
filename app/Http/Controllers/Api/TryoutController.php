<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('tryout-images', 'public');
        }

        $validated['created_by'] = $request->user()->id;

        $validated['is_published'] = $validated['is_published'] ?? false;

        $tryout = Tryout::create($validated);

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
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            if ($tryout->image && Storage::disk('public')->exists($tryout->image)) {
                Storage::disk('public')->delete($tryout->image);
            }
            $validated['image'] = $request->file('image')->store('tryout-images', 'public');
        }

        $validated['is_published'] = $validated['is_published'] ?? $tryout->is_published;

        $tryout->update($validated);

        return response()->json([
            'message' => 'Tryout berhasil diupdate',
            'data' => $tryout,
        ]);
    }

    public function destroy(Tryout $tryout): JsonResponse
    {
        if ($tryout->image && Storage::disk('public')->exists($tryout->image)) {
            Storage::disk('public')->delete($tryout->image);
        }

        $tryout->delete();

        return response()->json([
            'message' => 'Tryout berhasil dihapus',
        ]);
    }
}