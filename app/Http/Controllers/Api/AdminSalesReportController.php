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
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.status', ['paid', 'approved'])
            ->whereNotNull('o.paid_at')
            ->when($request->year,  fn($q, $y) => $q->whereRaw('YEAR(o.paid_at) = ?',  [$y]))
            ->when($request->month, fn($q, $m) => $q->whereRaw('MONTH(o.paid_at) = ?', [$m]))
            ->selectRaw('
                oi.package_name_snapshot AS package_name,
                YEAR(o.paid_at)          AS year,
                MONTH(o.paid_at)         AS month,
                COUNT(oi.id)             AS jumlah_peserta,
                SUM(oi.subtotal)         AS total_fee
            ')
            ->groupByRaw('oi.package_name_snapshot, YEAR(o.paid_at), MONTH(o.paid_at)')
            ->orderByRaw('YEAR(o.paid_at) DESC, MONTH(o.paid_at) DESC, oi.package_name_snapshot ASC')
            ->get();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total_peserta' => $rows->sum('jumlah_peserta'),
                'total_fee'     => $rows->sum('total_fee'),
            ],
        ]);
    }
}
