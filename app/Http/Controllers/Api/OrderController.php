<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with('items.package')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => ['required', 'string', 'exists:packages,id'],
        ]);

        $package = Package::where('is_active', true)->findOrFail($validated['package_id']);
        $finalPrice = $package->discount_price ?? $package->price;

        $existingOrder = Order::with('items.package')
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->whereHas('items', fn ($query) => $query->where('package_id', $package->id))
            ->latest()
            ->first();

        if ($existingOrder) {
            $snapToken = $existingOrder->midtrans_order_id;

            if (! $snapToken) {
                try {
                    $snapToken = $this->createSnapToken($existingOrder, $request);
                    $existingOrder->update(['midtrans_order_id' => $snapToken]);
                } catch (\Exception $e) {
                    return response()->json(['message' => 'Gagal membuka ulang pembayaran. Silakan cek status pembayaran di riwayat.'], 500);
                }
            }

            return response()->json([
                'message' => 'Lanjutkan pembayaran sebelumnya',
                'data' => $existingOrder->fresh()->load('items.package'),
                'snap_token' => $snapToken,
            ]);
        }

        $order = DB::transaction(function () use ($request, $package, $finalPrice) {
            $order = Order::create([
                'order_code' => 'ORD-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6)),
                'user_id' => $request->user()->id,
                'grand_total' => $finalPrice,
                'currency' => $package->currency ?? 'IDR',
                'status' => 'pending',
                'payment_method' => 'midtrans',
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'package_id' => $package->id,
                'package_name_snapshot' => $package->name,
                'price' => $finalPrice,
                'qty' => 1,
                'subtotal' => $finalPrice,
            ]);

            return $order;
        });

        try {
            $snapToken = $this->createSnapToken($order, $request);
            $order->update(['midtrans_order_id' => $snapToken]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal terhubung ke server pembayaran.'], 500);
        }

        AuditLogger::log('Order', 'create', "Order dibuat: #{$order->order_code} ({$package->name}) Rp" . number_format($finalPrice, 0, ',', '.'), $request->user(), $order);

        return response()->json([
            'message' => 'Silakan lakukan pembayaran',
            'data' => $order->load('items.package'),
            'snap_token' => $snapToken
        ], 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        return response()->json([
            'data' => $order->load('items.package'),
        ]);
    }

    public function verifyPayment(Request $request, Order $order, EnrollmentService $enrollmentService): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        if (in_array($order->status, ['paid', 'approved', 'cancelled', 'rejected'])) {
            return response()->json([
                'message' => 'Order sudah diproses.',
                'status'  => $order->status,
            ]);
        }

        Config::$serverKey   = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');

        try {
            $midtransStatus = Transaction::status($order->order_code);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengecek status pembayaran.'], 502);
        }

        $transactionStatus = $midtransStatus->transaction_status ?? '';
        $fraudStatus       = $midtransStatus->fraud_status ?? 'accept';

        if (in_array($transactionStatus, ['capture', 'settlement']) && $fraudStatus === 'accept') {
            if ($order->status !== 'paid') {
                $enrollmentService->approveOrderAndGrantAccess($order, null);
                $order->update([
                    'status'                  => 'paid',
                    'midtrans_transaction_id'  => $midtransStatus->transaction_id ?? null,
                    'payment_reference'        => $midtransStatus->payment_type ?? null,
                    'paid_at'                 => now(),
                ]);
            }
            return response()->json(['message' => 'Pembayaran dikonfirmasi.', 'status' => 'paid']);
        }

        if (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
            $order->update(['status' => 'cancelled']);
            return response()->json(['message' => 'Pembayaran dibatalkan/kedaluwarsa.', 'status' => 'cancelled']);
        }

        return response()->json(['message' => 'Pembayaran belum selesai.', 'status' => $order->status]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Hanya order dengan status pending yang bisa dibatalkan.'], 422);
        }

        $order->update(['status' => 'cancelled']);
        AuditLogger::log('Order', 'cancel', "Order dibatalkan: #{$order->order_code}", $request->user(), $order);

        return response()->json(['message' => 'Order berhasil dibatalkan.']);
    }

    private function createSnapToken(Order $order, Request $request): string
    {
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');

        return Snap::getSnapToken([
            'transaction_details' => [
                'order_id' => $order->order_code,
                'gross_amount' => $order->grand_total,
            ],
            'customer_details' => [
                'first_name' => $request->user()->name ?? 'Siswa',
                'email' => $request->user()->email,
            ],
        ]);
    }
}
