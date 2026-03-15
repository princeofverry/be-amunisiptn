<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = Package::latest()->get();

        return response()->json([
            'data' => $packages,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:packages,slug'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'ticket_amount' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $package = Package::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'ticket_amount' => $validated['ticket_amount'],
            'currency' => $validated['currency'] ?? 'IDR',
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Paket berhasil dibuat',
            'data' => $package,
        ], 201);
    }

    public function show(Package $package): JsonResponse
    {
        return response()->json([
            'data' => $package,
        ]);
    }

    public function update(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:packages,slug,' . $package->id],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'ticket_amount' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $package->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'ticket_amount' => $validated['ticket_amount'],
            'currency' => $validated['currency'] ?? 'IDR',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Paket berhasil diupdate',
            'data' => $package,
        ]);
    }

    public function destroy(Package $package): JsonResponse
    {
        $package->delete();

        return response()->json([
            'message' => 'Paket berhasil dihapus',
        ]);
    }
}