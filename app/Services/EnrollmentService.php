<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TicketLog;
use App\Models\User;
use App\Models\UserPackageEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

                if (! $package) {
                    Log::warning('EnrollmentService: package null untuk order item', [
                        'order_id'      => $order->id,
                        'order_item_id' => $item->id,
                    ]);
                    continue;
                }

                [, $created] = UserPackageEnrollment::firstOrCreate(
                    [
                        'user_id'    => $user->id,
                        'package_id' => $package->id,
                    ],
                    [
                        'order_id'    => $order->id,
                        'enrolled_at' => now(),
                    ]
                );

                $ticketAmount = (int) ($package->ticket_amount ?? 0);

                if ($created && $ticketAmount > 0) {
                    $user->ticket_balance += $ticketAmount;
                    TicketLog::create([
                        'user_id'     => $user->id,
                        'type'        => 'credit',
                        'amount'      => $ticketAmount,
                        'source'      => 'paket',
                        'description' => $package->name,
                    ]);
                }
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