<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Package;
use App\Models\Question;
use App\Models\TryoutSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminStatsController extends Controller
{
    public function index(): JsonResponse
    {
        $totalUsers    = User::where('role', 'user')->count();
        $totalTryouts  = \App\Models\Tryout::count();
        $totalOrders   = Order::count();
        $totalRevenue  = Order::whereIn('status', ['paid', 'approved'])->sum('grand_total');

        // Pengguna baru 7 hari terakhir
        $newUsersWeek = User::where('role', 'user')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        // Pengguna baru 30 hari terakhir
        $newUsersMonth = User::where('role', 'user')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // Total soal aktif
        $totalQuestions = Question::where('is_active', true)->count();

        // Total paket aktif
        $totalPackages = Package::where('is_active', true)->count();

        // Sesi ujian hari ini (proxy "pengunjung aktif")
        $sessionsToday = TryoutSession::whereDate('created_at', today())->count();

        // Top 5 tryout terlaris (berdasarkan jumlah enrollment)
        $topTryouts = DB::table('user_tryout_access')
            ->join('tryouts', 'tryouts.id', '=', 'user_tryout_access.tryout_id')
            ->select('tryouts.title', DB::raw('COUNT(*) as enrolled'))
            ->groupBy('tryouts.id', 'tryouts.title')
            ->orderByDesc('enrolled')
            ->limit(5)
            ->get();

        // Pendapatan per bulan
        $monthlyRevenue = Order::whereIn('status', ['paid', 'approved'])
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(grand_total) as total')
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->orderByRaw('YEAR(created_at), MONTH(created_at)')
            ->get()
            ->map(fn($r) => [
                'label' => sprintf('%d/%02d', $r->year, $r->month),
                'total' => $r->total,
            ]);

        // Pendaftaran pengguna per hari (30 hari terakhir)
        $userRegistrations = User::where('role', 'user')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'count' => $r->count]);

        // Status transaksi
        $orderByStatus = Order::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->map(fn($r) => ['status' => $r->status, 'count' => $r->count]);

        return response()->json([
            'data' => [
                'total_users'         => $totalUsers,
                'total_tryouts'       => $totalTryouts,
                'total_orders'        => $totalOrders,
                'total_revenue'       => $totalRevenue,
                'new_users_week'      => $newUsersWeek,
                'new_users_month'     => $newUsersMonth,
                'total_questions'     => $totalQuestions,
                'total_packages'      => $totalPackages,
                'sessions_today'      => $sessionsToday,
                'top_tryouts'         => $topTryouts,
                'monthly_revenue'     => $monthlyRevenue,
                'user_registrations'  => $userRegistrations,
                'order_by_status'     => $orderByStatus,
            ],
        ]);
    }
}
