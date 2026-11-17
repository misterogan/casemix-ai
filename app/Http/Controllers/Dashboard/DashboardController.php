<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardStats;
use Illuminate\Http\Request;

/** Pusat Kendali Klaim: hanya Koordinator (Admin tidak, demi privasi data pasien). */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats)
    {
        $period = DashboardStats::period($request->query('periode'));

        return view('dashboard.control', ['period' => $period, 's' => $stats->get($period)]);
    }
}
