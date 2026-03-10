<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
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