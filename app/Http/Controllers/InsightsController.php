<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InsightsService;
use App\Support\SpendingCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightsController extends Controller
{
    public function __construct(private InsightsService $insights) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->resolveUser();
        if (! $user) {
            return $this->emptyResponse();
        }

        $breakdown = $this->insights->getMonthlyBreakdown($user);
        $mom       = $this->insights->getMonthOverMonth($user);
        $budgets   = $this->insights->getBudgetStatus($user);
        $narratives = $this->insights->generateNarrativeInsights($user, $breakdown, $mom);

        $monthlyTotal = array_sum(array_map(fn ($r) => $r['total'], $breakdown));
        $previousTotal = array_sum(array_map(fn ($r) => $r['previous'], $mom));
        $totalDeltaPct = $previousTotal > 0
            ? round((($monthlyTotal - $previousTotal) / $previousTotal) * 100, 1)
            : null;

        return response()->json([
            'currency' => 'EUR',
            'month_total' => round($monthlyTotal, 2),
            'previous_month_total' => round($previousTotal, 2),
            'month_delta_pct' => $totalDeltaPct,
            'breakdown' => $breakdown,
            'month_over_month' => $mom,
            'narratives' => $narratives,
            'budgets' => $budgets,
            'categories' => array_map(fn ($k) => [
                'key' => $k,
                'label' => SpendingCategories::META[$k]['label'],
                'color' => SpendingCategories::META[$k]['color'],
            ], SpendingCategories::CATEGORIES),
        ]);
    }

    public function timeline(Request $request): JsonResponse
    {
        $user = $this->resolveUser();
        if (! $user) {
            return response()->json(['months' => [], 'series' => []]);
        }

        $months = (int) $request->query('months', 6);
        return response()->json($this->insights->getTimeline($user, $months));
    }

    public function budgetStatus(Request $request): JsonResponse
    {
        $user = $this->resolveUser();
        if (! $user) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->insights->getBudgetStatus($user)]);
    }

    /**
     * Single-user app: there's no auth yet, so we always use the first user.
     * Centralized here so we can swap in auth() later.
     */
    private function resolveUser(): ?User
    {
        return User::query()->first();
    }

    private function emptyResponse(): JsonResponse
    {
        return response()->json([
            'currency' => 'EUR',
            'month_total' => 0,
            'previous_month_total' => 0,
            'month_delta_pct' => null,
            'breakdown' => [],
            'month_over_month' => [],
            'narratives' => ['Scan your first receipt to start seeing spending insights.'],
            'budgets' => [],
            'categories' => array_map(fn ($k) => [
                'key' => $k,
                'label' => SpendingCategories::META[$k]['label'],
                'color' => SpendingCategories::META[$k]['color'],
            ], SpendingCategories::CATEGORIES),
        ]);
    }
}
