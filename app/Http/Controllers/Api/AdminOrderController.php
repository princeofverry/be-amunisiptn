<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function __construct(
        protected EnrollmentService $enrollmentService
    ) {}

    public function index(): JsonResponse
    {
        $orders = Order::with(['user', 'items.package'])->latest()->get();

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'data' => $order->load(['user', 'items.package.tryouts']),
        ]);
    }

    public function approve(Request $request, Order $order): JsonResponse
    {
        if (!in_array($order->status, ['pending', 'waiting_approval'])) {
            return response()->json([
                'message' => 'Order tidak bisa di-approve dari status saat ini',
            ], 422);
        }

        $order = $this->enrollmentService->approveOrderAndGrantAccess(
            $order,
            $request->user()->id
        );

        return response()->json([
            'message' => 'Order berhasil di-approve dan user sudah di-enroll',
            'data' => $order,
        ]);
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string'],
        ]);

        $order = $this->enrollmentService->rejectOrder(
            $order,
            $validated['admin_note'] ?? null
        );

        return response()->json([
            'message' => 'Order ditolak',
            'data' => $order,
        ]);
    }
}