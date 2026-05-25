<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSalesReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $baseQuery = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.status', ['paid', 'approved'])
            ->whereNotNull('o.paid_at')
            ->when($request->year,  fn($q, $y) => $q->whereRaw('YEAR(o.paid_at) = ?',  [$y]))
            ->when($request->month, fn($q, $m) => $q->whereRaw('MONTH(o.paid_at) = ?', [$m]));

        $rows = (clone $baseQuery)
            ->selectRaw('
                oi.package_name_snapshot      AS product_name,
                YEAR(o.paid_at)               AS year,
                MONTH(o.paid_at)              AS month,
                MIN(DATE(o.paid_at))          AS period_start,
                SUM(oi.qty)                   AS total_item_sold,
                COUNT(DISTINCT o.id)          AS order_count,
                ROUND(AVG(oi.price))          AS average_price,
                SUM(oi.subtotal)              AS total_sales
            ')
            ->groupByRaw('oi.package_name_snapshot, YEAR(o.paid_at), MONTH(o.paid_at)')
            ->orderByRaw('YEAR(o.paid_at) DESC, MONTH(o.paid_at) DESC, oi.package_name_snapshot ASC')
            ->get();

        $totalSales = (int) $rows->sum('total_sales');
        $totalItemSold = (int) $rows->sum('total_item_sold');
        $totalOrders = (int) (clone $baseQuery)->distinct('o.id')->count('o.id');

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total_sales' => $totalSales,
                'total_item_sold' => $totalItemSold,
                'amunisi_revenue' => (int) round($totalSales * 0.8),
                'developer_revenue' => (int) round($totalSales * 0.2),
                'order_count' => $totalOrders,
            ],
        ]);
    }

    public function feeTryout(Request $request): JsonResponse
    {
        $feePerParticipant = 6000;

        $rows = DB::table('user_tryout_access as uta')
            ->join('tryouts as t', 't.id', '=', 'uta.tryout_id')
            ->when($request->year, fn($q, $y) => $q->whereRaw('YEAR(uta.granted_at) = ?', [$y]))
            ->when($request->month, fn($q, $m) => $q->whereRaw('MONTH(uta.granted_at) = ?', [$m]))
            ->where('t.is_free', false)
            ->selectRaw('
            t.id                        AS tryout_id,
            t.title                     AS tryout_name,
            YEAR(uta.granted_at)        AS year,
            MONTH(uta.granted_at)       AS month,
            MIN(DATE(uta.granted_at))   AS period_start,
            COUNT(DISTINCT uta.user_id) AS participant_count,
            COUNT(uta.id)               AS access_count
        ')
            ->groupByRaw('t.id, t.title, YEAR(uta.granted_at), MONTH(uta.granted_at)')
            ->orderByRaw('YEAR(uta.granted_at) DESC, MONTH(uta.granted_at) DESC, t.title ASC')
            ->get()
            ->map(function ($row) use ($feePerParticipant) {
                $row->participant_count = (int) $row->participant_count;
                $row->access_count = (int) $row->access_count;
                $row->total_fee = $row->participant_count * $feePerParticipant;

                return $row;
            });

        $totalFee = (int) $rows->sum('total_fee');
        $totalParticipants = (int) $rows->sum('participant_count');
        $tryoutCount = $rows->pluck('tryout_id')->unique()->count();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'fee_per_participant' => $feePerParticipant,
                'total_fee' => $totalFee,
                'total_participants' => $totalParticipants,
                'tryout_count' => $tryoutCount,
                'average_fee_per_tryout' => $tryoutCount > 0 ? (int) round($totalFee / $tryoutCount) : 0,
            ],
        ]);
    }
}
