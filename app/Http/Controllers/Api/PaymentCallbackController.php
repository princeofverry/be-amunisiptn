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
        // Setup konfigurasi
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');

        try {
            $notification = new Notification();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid notification'], 400);
        }

        $transactionStatus = $notification->transaction_status;
        $orderId = $notification->order_id;
        
        // Cari order berdasarkan order_code
        $order = Order::where('order_code', $orderId)->first();

        if (!$order) {
            return response()->json(['message' => 'Order tidak ditemukan'], 404);
        }

        // Jika transaksi berhasil (settlement atau capture)
        if ($transactionStatus == 'capture' || $transactionStatus == 'settlement') {
            if ($order->status !== 'paid') {
                $enrollmentService->approveOrderAndGrantAccess($order, null);
                
                $order->update([
                    'payment_reference' => $notification->payment_type,
                    'midtrans_transaction_id' => $notification->transaction_id,
                ]);
            }
        } 
        // Jika kadaluarsa atau dibatalkan
        else if ($transactionStatus == 'cancel' || $transactionStatus == 'deny' || $transactionStatus == 'expire') {
            if ($order->status !== 'paid') {
                $order->update(['status' => 'cancelled']);
            }
        } 
        // Jika masih pending
        else if ($transactionStatus == 'pending') {
            $order->update(['status' => 'pending']);
        }

        return response()->json(['message' => 'Callback diterima sukses']);
    }
}