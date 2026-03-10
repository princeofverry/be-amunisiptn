<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Jangan lupa import Log

class PaymentCallbackController extends Controller
{
    public function handle(Request $request, EnrollmentService $enrollmentService)
    {
        // 1. Logging Request untuk keperluan Debugging
        Log::info('Midtrans Webhook Received', $request->all());

        $serverKey = config('midtrans.server_key');

        $orderCode = $request->order_id;
        $statusCode = $request->status_code;
        $grossAmount = $request->gross_amount;
        $signatureKey = $request->signature_key;
        $transactionStatus = $request->transaction_status;
        $fraudStatus = $request->fraud_status; // Ambil fraud status

        // 2. Validasi Keamanan: Cek Signature Key
        $validSignature = hash('sha512', $orderCode . $statusCode . $grossAmount . $serverKey);
        
        if ($validSignature !== $signatureKey) {
            Log::warning('Midtrans Invalid Signature', ['order' => $orderCode]);
            // Jangan lupa buka comment ini saat naik ke Production!
            // return response()->json(['message' => 'Invalid signature key'], 403);
        }

        // 3. Cari Order berdasarkan Order Code
        $order = Order::where('order_code', $orderCode)->first();

        if (!$order) {
            Log::error('Midtrans Order Not Found', ['order' => $orderCode]);
            return response()->json(['message' => 'Order tidak ditemukan'], 404);
        }

        // 4. Validasi Nominal Pembayaran (Mencegah manipulasi harga)
        // Midtrans mengirim gross_amount dengan format string dan 2 desimal (misal: "150000.00")
        // Sesuaikan "$order->total_price" dengan nama kolom harga di tabel order kamu.
        if ((float) $order->total_price !== (float) $grossAmount) {
            Log::critical('Midtrans Gross Amount Mismatch!', [
                'order' => $orderCode, 
                'db_price' => $order->total_price, 
                'midtrans_price' => $grossAmount
            ]);
            return response()->json(['message' => 'Nominal pembayaran tidak valid'], 400);
        }

        // 5. Update status berdasarkan notifikasi
        if ($transactionStatus == 'capture') {
            // Khusus Kartu Kredit, cek fraud status
            if ($fraudStatus == 'accept') {
                $this->processSuccessOrder($order, $request, $enrollmentService);
            } else if ($fraudStatus == 'challenge') {
                // Opsional: Tandai sebagai manual review / pending
                $order->update(['status' => 'challenge']);
            }
        } else if ($transactionStatus == 'settlement') {
            // Sukses untuk QRIS, Virtual Account, e-Wallet, dll
            $this->processSuccessOrder($order, $request, $enrollmentService);
        } else if (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
            if ($order->status !== 'paid') {
                $order->update(['status' => 'cancelled']);
            }
        }

        return response()->json(['message' => 'Callback diproses']);
    }

    // Ekstrak logika sukses agar kode lebih bersih (DRY)
    private function processSuccessOrder($order, $request, $enrollmentService)
    {
        if ($order->status !== 'paid') {
            $enrollmentService->approveOrderAndGrantAccess($order, null);
            
            $order->update([
                // Pastikan kolom database sesuai ('status' diupdate di service atau di sini?)
                'status' => 'paid', // Tambahkan ini jika di Service belum ada update status
                'midtrans_transaction_id' => $request->transaction_id,
                'payment_reference' => $request->payment_type,
            ]);
        }
    }
}