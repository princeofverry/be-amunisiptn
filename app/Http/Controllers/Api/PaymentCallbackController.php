<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Notification;

class PaymentCallbackController extends Controller
{
    public function handle(Request $request, EnrollmentService $enrollmentService)
    {
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');

        try {
            $notification = new Notification();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Notifikasi tidak valid'], 400);
        }

        $transactionStatus = $notification->transaction_status;
        $orderCode = $notification->order_id;
        
        $order = Order::where('order_code', $orderCode)->first();

        if (!$order) {
            return response()->json(['message' => 'Order tidak ditemukan'], 404);
        }

        if ($transactionStatus == 'capture' || $transactionStatus == 'settlement') {
            if ($order->status !== 'paid') {
                $enrollmentService->approveOrderAndGrantAccess($order, null);
                
                $order->update([
                    'midtrans_transaction_id' => $notification->transaction_id,
                    'payment_reference' => $notification->payment_type,
                ]);
            }
        } 
        else if (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
            if ($order->status !== 'paid') {
                $order->update(['status' => 'cancelled']);
            }
        }

        return response()->json(['message' => 'Callback diproses']);
    }
}