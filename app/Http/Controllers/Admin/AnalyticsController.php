<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\AnalyticsRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function funnel(Request $request, AnalyticsRepository $analytics): Response
    {
        $from = $request->date('from', 'Y-m-d') ?? now()->subDays(7)->startOfDay();
        $to = $request->date('to', 'Y-m-d') ?? now()->endOfDay();

        return Inertia::render('Admin/Analytics/Funnel', [
            'steps' => $analytics->funnelSteps($from, $to),
            'promptBreakdown' => $analytics->funnelByPromptVersion($from, $to),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }
}
