<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\UserPackageEnrollment;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    public function approveOrderAndGrantAccess(Order $order, ?string $adminId = null): Order
    {
        return DB::transaction(function () use ($order, $adminId) {
            $order->load('items.package');

            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
                'approved_at' => now(),
                'approved_by' => $adminId,
            ]);

            $user = User::lockForUpdate()->find($order->user_id);

            foreach ($order->items as $item) {
                $package = $item->package;

                UserPackageEnrollment::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'package_id' => $package->id,
                    ],
                    [
                        'order_id' => $order->id,
                        'enrolled_at' => now(),
                    ]
                );

                $user->ticket_balance += $package->ticket_amount;
            }

            $user->save();

            return $order->fresh(['items.package']);
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