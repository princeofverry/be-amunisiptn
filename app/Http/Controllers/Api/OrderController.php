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
            'payment_method' => ['nullable', 'in:manual_transfer,midtrans'],
        ]);

        $package = Package::where('is_active', true)
            ->findOrFail($validated['package_id']);

        $order = DB::transaction(function () use ($request, $package, $validated) {
            $order = Order::create([
                'order_code' => 'ORD-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6)),
                'user_id' => $request->user()->id,
                'grand_total' => $package->price,
                'currency' => $package->currency ?? 'IDR',
                'status' => 'waiting_approval',
                'payment_method' => $validated['payment_method'] ?? 'manual_transfer',
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

        return response()->json([
            'message' => 'Order berhasil dibuat',
            'data' => $order->load('items.package'),
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