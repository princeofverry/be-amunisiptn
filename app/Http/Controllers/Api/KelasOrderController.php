<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\KelasOrder;
use App\Models\User;
use App\Models\UserKelasEnrollment;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;

class KelasOrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kelas_id' => ['required', 'string', 'exists:kelas,id'],
        ]);

        $kelas = Kelas::findOrFail($validated['kelas_id']);

        if (!$kelas->is_active) {
            return response()->json(['message' => 'Kelas ini tidak tersedia.'], 422);
        }

        $alreadyEnrolled = UserKelasEnrollment::where('user_id', $request->user()->id)
            ->where('kelas_id', $kelas->id)
            ->exists();

        if ($alreadyEnrolled) {
            return response()->json(['message' => 'Anda sudah terdaftar di kelas ini.'], 422);
        }

        $finalPrice = $kelas->discount_price ?? $kelas->price;

        $order = KelasOrder::create([
            'order_code'  => 'KLAS-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6)),
            'user_id'     => $request->user()->id,
            'kelas_id'    => $kelas->id,
            'grand_total' => $finalPrice,
            'currency'    => 'IDR',
            'status'      => 'pending',
        ]);

        // Setup Midtrans
        Config::$serverKey   = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds       = config('midtrans.is_3ds');

        $params = [
            'transaction_details' => [
                'order_id'     => $order->order_code,
                'gross_amount' => $order->grand_total,
            ],
            'customer_details' => [
                'first_name' => $request->user()->name ?? 'Siswa',
                'email'      => $request->user()->email,
            ],
            'item_details' => [
                [
                    'id'       => $kelas->id,
                    'price'    => $finalPrice,
                    'quantity' => 1,
                    'name'     => $kelas->name,
                ],
            ],
        ];

        try {
            $snapToken = Snap::getSnapToken($params);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal terhubung ke server pembayaran.'], 500);
        }

        AuditLogger::log(
            'Kelas',
            'order',
            "Order kelas dibuat: #{$order->order_code} ({$kelas->name}) Rp" . number_format($finalPrice, 0, ',', '.'),
            $request->user(),
            $order
        );

        return response()->json([
            'message'    => 'Silakan lakukan pembayaran',
            'data'       => $order->load('kelas'),
            'snap_token' => $snapToken,
        ], 201);
    }

    public function cancel(Request $request, KelasOrder $kelasOrder): JsonResponse
    {
        abort_unless($kelasOrder->user_id === $request->user()->id, 403);

        if ($kelasOrder->status !== 'pending') {
            return response()->json(['message' => 'Hanya order dengan status pending yang bisa dibatalkan.'], 422);
        }

        $kelasOrder->update(['status' => 'cancelled']);

        AuditLogger::log(
            'Kelas',
            'cancel',
            "Order kelas dibatalkan: #{$kelasOrder->order_code}",
            $request->user(),
            $kelasOrder
        );

        return response()->json(['message' => 'Order berhasil dibatalkan.']);
    }

    public function verifyPayment(Request $request, KelasOrder $kelasOrder): JsonResponse
    {
        abort_unless($kelasOrder->user_id === $request->user()->id, 403);

        if (in_array($kelasOrder->status, ['paid', 'cancelled'])) {
            return response()->json([
                'message' => 'Order sudah diproses.',
                'status'  => $kelasOrder->status,
            ]);
        }

        Config::$serverKey    = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');

        try {
            $midtransStatus = Transaction::status($kelasOrder->order_code);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengecek status pembayaran.'], 502);
        }

        $transactionStatus = $midtransStatus->transaction_status ?? '';
        $fraudStatus       = $midtransStatus->fraud_status ?? 'accept';

        if (in_array($transactionStatus, ['capture', 'settlement']) && $fraudStatus === 'accept') {
            if ($kelasOrder->status !== 'paid') {
                $this->processKelasPayment($kelasOrder, $request->user(), $midtransStatus);
            }
            return response()->json(['message' => 'Pembayaran dikonfirmasi.', 'status' => 'paid']);
        }

        if (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
            $kelasOrder->update(['status' => 'cancelled']);
            return response()->json(['message' => 'Pembayaran dibatalkan/kedaluwarsa.', 'status' => 'cancelled']);
        }

        return response()->json(['message' => 'Pembayaran belum selesai.', 'status' => $kelasOrder->status]);
    }

    private function processKelasPayment(KelasOrder $order, User $user, $midtransStatus = null): void
    {
        DB::transaction(function () use ($order, $user, $midtransStatus) {
            $order->update([
                'status'                 => 'paid',
                'paid_at'                => now(),
                'midtrans_transaction_id' => $midtransStatus->transaction_id ?? null,
                'payment_reference'      => $midtransStatus->payment_type ?? null,
            ]);

            UserKelasEnrollment::firstOrCreate(
                [
                    'user_id'  => $order->user_id,
                    'kelas_id' => $order->kelas_id,
                ],
                [
                    'kelas_order_id' => $order->id,
                    'enrolled_at'    => now(),
                ]
            );

            $userModel = User::lockForUpdate()->find($user->id);
            $userModel->ticket_balance += $order->kelas->ticket_amount;
            $userModel->save();
        });

        AuditLogger::log(
            'Kelas',
            'payment',
            "Pembayaran kelas dikonfirmasi: #{$order->order_code}",
            $user,
            $order
        );
    }
}
