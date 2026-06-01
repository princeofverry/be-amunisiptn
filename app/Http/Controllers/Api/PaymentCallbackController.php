<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KelasOrder;
use App\Models\Order;
use App\Models\User;
use App\Models\UserKelasEnrollment;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{
    public function handle(Request $request, EnrollmentService $enrollmentService)
    {
        // 1. Logging Request untuk keperluan Debugging
        Log::info('Midtrans Webhook Received', $request->all());

        $serverKey = config('midtrans.server_key');

        $orderCode         = $request->order_id;
        $statusCode        = $request->status_code;
        $grossAmount       = $request->gross_amount;
        $signatureKey      = $request->signature_key;
        $transactionStatus = $request->transaction_status;
        $fraudStatus       = $request->fraud_status;

        // 2. Validasi Keamanan: Cek Signature Key
        $validSignature = hash('sha512', $orderCode . $statusCode . $grossAmount . $serverKey);

        if ($validSignature !== $signatureKey) {
            Log::warning('Midtrans Invalid Signature', ['order' => $orderCode]);
            return response()->json(['message' => 'Invalid signature key'], 403);
        }

        // 3. Routing berdasarkan prefix order code
        if (str_starts_with($orderCode, 'KLAS-')) {
            return $this->handleKelasCallback(
                $request,
                $orderCode,
                $grossAmount,
                $transactionStatus,
                $fraudStatus
            );
        }

        // 4. Cari Order package berdasarkan Order Code
        $order = Order::where('order_code', $orderCode)->first();

        if (!$order) {
            Log::error('Midtrans Order Not Found', ['order' => $orderCode]);
            return response()->json(['message' => 'Order tidak ditemukan'], 404);
        }

        // 5. Validasi Nominal Pembayaran
        if ((float) $order->grand_total !== (float) $grossAmount) {
            Log::critical('Midtrans Gross Amount Mismatch!', [
                'order'          => $orderCode,
                'db_price'       => $order->grand_total,
                'midtrans_price' => $grossAmount,
            ]);
            return response()->json(['message' => 'Nominal pembayaran tidak valid'], 400);
        }

        // 6. Update status berdasarkan notifikasi
        try {
            if ($transactionStatus == 'capture') {
                if ($fraudStatus == 'accept') {
                    $this->processSuccessOrder($order, $request, $enrollmentService);
                } else if ($fraudStatus == 'challenge') {
                    $order->update(['status' => 'pending']);
                }
            } else if ($transactionStatus == 'settlement') {
                $this->processSuccessOrder($order, $request, $enrollmentService);
            } else if (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
                if ($order->status !== 'paid') {
                    $order->update(['status' => 'cancelled']);
                }
            }
        } catch (\Exception $e) {
            Log::error('Midtrans Webhook Processing Failed', [
                'order'   => $orderCode,
                'status'  => $transactionStatus,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            // Return 500 so Midtrans retries the notification automatically
            return response()->json(['message' => 'Gagal memproses pembayaran, akan dicoba ulang'], 500);
        }

        return response()->json(['message' => 'Callback diproses']);
    }

    private function handleKelasCallback(
        Request $request,
        string $orderCode,
        $grossAmount,
        string $transactionStatus,
        ?string $fraudStatus
    ) {
        $kelasOrder = KelasOrder::where('order_code', $orderCode)->first();

        if (!$kelasOrder) {
            Log::error('Midtrans Kelas Order Not Found', ['order' => $orderCode]);
            return response()->json(['message' => 'Order tidak ditemukan'], 404);
        }

        // Validasi nominal pembayaran
        if ((float) $kelasOrder->grand_total !== (float) $grossAmount) {
            Log::critical('Midtrans Kelas Gross Amount Mismatch!', [
                'order'          => $orderCode,
                'db_price'       => $kelasOrder->grand_total,
                'midtrans_price' => $grossAmount,
            ]);
            return response()->json(['message' => 'Nominal tidak valid'], 400);
        }

        if ($transactionStatus == 'settlement' || ($transactionStatus == 'capture' && $fraudStatus == 'accept')) {
            $this->processKelasOrder($kelasOrder, $request->transaction_id, $request->payment_type);
        } elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
            DB::transaction(function () use ($kelasOrder) {
                $locked = KelasOrder::lockForUpdate()->find($kelasOrder->id);
                if ($locked->status !== 'paid') {
                    $locked->update(['status' => 'cancelled']);
                }
            });
        }

        return response()->json(['message' => 'Callback diproses']);
    }

    private function processKelasOrder(KelasOrder $order, ?string $transactionId = null, ?string $paymentType = null): void
    {
        DB::transaction(function () use ($order, $transactionId, $paymentType) {
            // Re-fetch with lock to prevent concurrent webhook race condition
            $locked = KelasOrder::lockForUpdate()->find($order->id);
            if ($locked->status === 'paid') {
                return;
            }

            $locked->update([
                'status'                  => 'paid',
                'paid_at'                 => now(),
                'midtrans_transaction_id' => $transactionId,
                'payment_reference'       => $paymentType,
            ]);

            [, $created] = UserKelasEnrollment::firstOrCreate(
                [
                    'user_id'  => $locked->user_id,
                    'kelas_id' => $locked->kelas_id,
                ],
                [
                    'kelas_order_id' => $locked->id,
                    'enrolled_at'    => now(),
                ]
            );

            $user = User::lockForUpdate()->find($locked->user_id);
            if ($created) {
                $user->ticket_balance += $locked->kelas->ticket_amount;
            }
            $user->save();
        });
    }

    private function processSuccessOrder($order, $request, $enrollmentService)
    {
        DB::transaction(function () use ($order, $request, $enrollmentService) {
            $locked = Order::lockForUpdate()->find($order->id);
            if ($locked->status === 'paid') {
                return;
            }

            $enrollmentService->approveOrderAndGrantAccess($locked, null);

            $locked->update([
                'status'                  => 'paid',
                'midtrans_transaction_id' => $request->transaction_id,
                'payment_reference'       => $request->payment_type,
                'paid_at'                 => now(),
            ]);
        });
    }
}
