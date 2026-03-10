<?php

namespace App\Services;

use App\Models\Order;
use App\Models\UserPackageEnrollment;
use App\Models\UserTryoutAccess;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    // Ubah $adminId menjadi nullable (?string) dengan default null
    public function approveOrderAndGrantAccess(Order $order, ?string $adminId = null): Order
    {
        return DB::transaction(function () use ($order, $adminId) {
            $order->load('items.package.tryouts');

            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
                'approved_at' => now(),
                'approved_by' => $adminId, // Ini akan terisi null karena di-acc oleh sistem
            ]);

            $userId = $order->user_id;

            foreach ($order->items as $item) {
                $package = $item->package;

                // Enroll user ke paket
                UserPackageEnrollment::firstOrCreate(
                    [
                        'user_id' => $userId,
                        'package_id' => $package->id,
                    ],
                    [
                        'order_id' => $order->id,
                        'enrolled_at' => now(),
                    ]
                );

                // Berikan akses tryout di dalam paket
                foreach ($package->tryouts as $tryout) {
                    UserTryoutAccess::firstOrCreate(
                        [
                            'user_id' => $userId,
                            'tryout_id' => $tryout->id,
                        ],
                        [
                            'access_code_id' => null,
                            'granted_at' => now(),
                        ]
                    );
                }
            }

            return $order->fresh(['items.package.tryouts']);
        });
    }

    public function rejectOrder(Order $order, ?string $note = null): Order
    {
        $order->update([
            'status' => 'rejected',
            'admin_note' => $note,
        ]);

        return $order->fresh(['items.package']);
    }
}