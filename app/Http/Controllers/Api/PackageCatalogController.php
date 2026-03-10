<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;

class PackageCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = Package::with('tryouts')
            ->where('is_active', true)
            ->latest()
            ->get();

        return response()->json([
            'data' => $packages,
        ]);
    }

    public function show(Package $package): JsonResponse
    {
        abort_unless($package->is_active, 404);

        return response()->json([
            'data' => $package->load('tryouts'),
        ]);
    }
}