<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::latest()
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
            ->paginate(15);

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'Anda tidak dapat menghapus akun Admin Anda sendiri.'
            ], 403);
        }

        $user->tokens()->delete();

        $user->delete();

        return response()->json([
            'message' => 'Data pengguna berhasil dihapus oleh Admin.'
        ]);
    }
}