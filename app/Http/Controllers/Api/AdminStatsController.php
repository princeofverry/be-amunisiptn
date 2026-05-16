<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Tryout;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminStatsController extends Controller
{
    public function index(): JsonResponse
    {
        $totalUsers = User::where('role', 'user')->count();
        $totalTryouts = Tryout::count();
        $totalOrders = Order::count();
        $totalRevenue = Order::whereIn('status', ['paid', 'approved'])->sum('grand_total');

        $monthlyRevenue = Order::whereIn('status', ['paid', 'approved'])
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(grand_total) as total')
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->orderByRaw('YEAR(created_at), MONTH(created_at)')
            ->get()
            ->map(fn($r) => [
                'label' => sprintf('%d/%02d', $r->year, $r->month),
                'total' => $r->total,
            ]);

        $orderByStatus = Order::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->map(fn($r) => ['status' => $r->status, 'count' => $r->count]);

        return response()->json([
            'data' => [
                'total_users'    => $totalUsers,
                'total_tryouts'  => $totalTryouts,
                'total_orders'   => $totalOrders,
                'total_revenue'  => $totalRevenue,
                'monthly_revenue' => $monthlyRevenue,
                'order_by_status' => $orderByStatus,
            ],
        ]);
    }
}
