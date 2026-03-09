<?php

namespace App\Services;

use App\Models\Order;
use App\Models\UserPackageEnrollment;
use App\Models\UserTryoutAccess;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    public function approveOrderAndGrantAccess(Order $order, string $adminId): Order
    {
        return DB::transaction(function () use ($order, $adminId) {
            $order->load('items.package.tryouts');

            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
                'approved_at' => now(),
                'approved_by' => $adminId,
            ]);

            $userId = $order->user_id;

            foreach ($order->items as $item) {
                $package = $item->package;

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