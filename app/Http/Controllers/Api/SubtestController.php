<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subtest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubtestController extends Controller
{
    public function index(): JsonResponse
    {
        $subtests = Subtest::orderBy('category')
            ->orderBy('id')
            ->get();

            return response()->json([
                'data' => $subtests,
            ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:subtests,name'],
            'category' => ['required', 'in:TPS,Literasi'],
            'max_questions' => ['required', 'integer', 'min:1'],
        ]);

        $subtest = Subtest::create($validated);

        return response()->json([
            'message' => 'Subtest created successfully',
            'subtest' => $subtest,
        ], 201);
    }

    public function update(Request $request, Subtest $subtest): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:subtests,name,' . $subtest->id],
            'category' => ['required', 'in:TPS,Literasi'],
        ]);

        $subtest->update($validated);

        return response()->json([
            'message' => 'Subtest updated successfully',
            'subtest' => $subtest,
        ]);
    }

    public function destroy(Subtest $subtest): JsonResponse
    {
        $subtest->delete();

        return response()->json([
            'message' => 'Subtest deleted successfully',
        ]);
    }

    public function show(Subtest $subtest): JsonResponse
    {
        return response()->json([
            'data' => $subtest,
        ]);
    }
}