<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\Snap;

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
        // Validasi hanya butuh package_id, tidak perlu tanya metode pembayaran lagi
        $validated = $request->validate([
            'package_id' => ['required', 'string', 'exists:packages,id'],
        ]);

        $package = Package::where('is_active', true)->findOrFail($validated['package_id']);

        $order = DB::transaction(function () use ($request, $package) {
            $order = Order::create([
                'order_code' => 'ORD-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6)),
                'user_id' => $request->user()->id,
                'grand_total' => $package->price,
                'currency' => $package->currency ?? 'IDR',
                'status' => 'pending', // Langsung pending (menunggu pembayaran)
                'payment_method' => 'midtrans', // Fix 100% midtrans
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'package_id' => $package->id,
                'package_name_snapshot' => $package->name,
                'price' => $package->price,
                'qty' => 1,
                'subtotal' => $package->price,
            ]);

            return $order;
        });

        // Setup Midtrans
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');

        $params = [
            'transaction_details' => [
                'order_id' => $order->order_code,
                'gross_amount' => $order->grand_total,
            ],
            'customer_details' => [
                'first_name' => $request->user()->name ?? 'Siswa',
                'email' => $request->user()->email,
            ],
        ];

        try {
            $snapToken = Snap::getSnapToken($params);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal terhubung ke server pembayaran.'], 500);
        }

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
}