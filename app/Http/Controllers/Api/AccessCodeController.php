<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\UserTryoutAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessCodeController extends Controller
{
    //
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        $accessCode = AccessCode::with('tryout')
            ->where('code', $validated['code'])
            ->first();

        if (! $accessCode) {
            return response()->json([
                'message' => 'Kode akses tidak ditemukan',
            ], 404);
        }
        
        if (! $accessCode->isUsable()) {
            return response()->json([
                'message' => 'Kode akses tidak bisa digunakan',
            ], 422);
        }

        $alreadyHasAccess = UserTryoutAccess::where('user_id', $user->id)
            ->where('tryout_id', $accessCode->tryout_id)
            ->exists();

        if($alreadyHasAccess) {
            return response()->json([
                'message' => 'Anda sudah memiliki akses untuk tryout ini',
            ], 422);
        }

        DB::transaction(function () use ($user, $accessCode) {
            UserTryoutAccess::create([
                'user_id' => $user->id,
                'tryout_id' => $accessCode->tryout_id,
                'access_code_id' => $accessCode->id,
                'granted_at' => now(),
            ]);

            $accessCode->increment('used_count');
        });

        return response()->json([
            'message' => 'Kode akses berhasil digunakan',
            'data' => [
                'tryout_id' => $accessCode->tryout_id,
                'tryout_title' => $accessCode->tryout->title,
            ],
        ]);
    }
}