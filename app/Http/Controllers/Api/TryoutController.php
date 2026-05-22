<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TryoutController extends Controller
{
    public function index(): JsonResponse
    {
        $tryouts = Tryout::with(['creator', 'tryoutSubtests.subtest'])
            ->withCount('userAccesses')
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
            'category' => ['nullable', 'string', Rule::in(['UTBK', 'UM'])],
            'is_free' => ['nullable', 'boolean'],
            'use_irt' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('tryout-images', 'public');
        }

        $validated['created_by'] = $request->user()->id;
        $validated['is_free'] = $validated['is_free'] ?? false;
        $validated['use_irt'] = $validated['use_irt'] ?? true;
        $validated['is_published'] = $validated['is_published'] ?? false;

        $tryout = Tryout::create($validated);
        AuditLogger::log('Tryout', 'create', "Tryout dibuat: \"{$tryout->title}\"", $request->user(), $tryout);

        return response()->json([
            'message' => 'Tryout berhasil dibuat',
            'data' => $tryout,
        ], 201);
    }

    public function show(Tryout $tryout): JsonResponse
    {
        $tryout->load(['creator', 'tryoutSubtests.subtest'])
            ->loadCount('userAccesses');

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
            'category' => ['nullable', 'string', Rule::in(['UTBK', 'UM'])],
            'is_free' => ['nullable', 'boolean'],
            'use_irt' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            if ($tryout->image && Storage::disk('public')->exists($tryout->image)) {
                Storage::disk('public')->delete($tryout->image);
            }
            $validated['image'] = $request->file('image')->store('tryout-images', 'public');
        }

        $validated['is_free'] = $validated['is_free'] ?? $tryout->is_free;
        $validated['use_irt'] = $validated['use_irt'] ?? $tryout->use_irt;
        $validated['is_published'] = $validated['is_published'] ?? $tryout->is_published;

        $tryout->update($validated);
        AuditLogger::log('Tryout', 'update', "Tryout diupdate: \"{$tryout->title}\"", $request->user(), $tryout);

        return response()->json([
            'message' => 'Tryout berhasil diupdate',
            'data' => $tryout,
        ]);
    }

    public function destroy(Request $request, Tryout $tryout): JsonResponse
    {
        if ($tryout->image && Storage::disk('public')->exists($tryout->image)) {
            Storage::disk('public')->delete($tryout->image);
        }

        AuditLogger::log('Tryout', 'delete', "Tryout dihapus: \"{$tryout->title}\"", $request->user());
        $tryout->delete();

        return response()->json([
            'message' => 'Tryout berhasil dihapus',
        ]);
    }
}
