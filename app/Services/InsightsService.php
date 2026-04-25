<?php

namespace App\Services;

use App\Models\CategoryBudget;
use App\Models\User;
use App\Support\SpendingCategories;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Computes spending breakdowns, month-over-month deltas, AI narratives and
 * budget status for the AI Spending Coach dashboard.
 *
 * All public methods are read-only; persistence happens elsewhere
 * (CategoryBudgetController for budgets, ClaudeController for items).
 */
class InsightsService
{
    /**
     * @return array{ category: string, total: float, items: int, label: string, color: string }[]
     */
    public function getMonthlyBreakdown(User $user, ?CarbonImmutable $month = null): array
    {
        $month = $month ?? CarbonImmutable::now()->startOfMonth();
        $start = $month->startOfMonth();
        $end   = $month->endOfMonth();

        $rows = DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->where('receipts.user_id', $user->id)
            ->whereBetween(DB::raw('COALESCE(receipts.purchased_at, receipts.created_at)'), [$start, $end])
            ->groupBy('receipt_items.category')
            ->select(
                'receipt_items.category',
                DB::raw('SUM(receipt_items.price * receipt_items.quantity) as total'),
                DB::raw('COUNT(receipt_items.id) as items'),
            )
            ->get();

        return $rows->map(function ($row) {
            $category = SpendingCategories::normalize($row->category);
            $meta = SpendingCategories::META[$category];
            return [
                'category' => $category,
                'total' => round((float) $row->total, 2),
                'items' => (int) $row->items,
                'label' => $meta['label'],
                'color' => $meta['color'],
            ];
        })->sortByDesc('total')->values()->all();
    }

    /**
     * @return array<string, array{ current: float, previous: float, delta_pct: float|null }>
     */
    public function getMonthOverMonth(User $user): array
    {
        $now      = CarbonImmutable::now()->startOfMonth();
        $previous = $now->subMonthNoOverflow();

        $current  = collect($this->getMonthlyBreakdown($user, $now))->keyBy('category');
        $prior    = collect($this->getMonthlyBreakdown($user, $previous))->keyBy('category');

        $allKeys = $current->keys()->merge($prior->keys())->unique()->values();

        $out = [];
        foreach ($allKeys as $cat) {
            $cur = (float) ($current[$cat]['total'] ?? 0);
            $pre = (float) ($prior[$cat]['total'] ?? 0);
            $delta = null;
            if ($pre > 0) {
                $delta = round((($cur - $pre) / $pre) * 100, 1);
            } elseif ($cur > 0) {
                $delta = null;
            }
            $out[$cat] = [
                'current' => round($cur, 2),
                'previous' => round($pre, 2),
                'delta_pct' => $delta,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{ category: string, total: float, items: int, label: string, color: string }>  $breakdown
     * @param  array<string, array{ current: float, previous: float, delta_pct: float|null }>  $mom
     * @return string[]
     */
    public function generateNarrativeInsights(User $user, array $breakdown, array $mom): array
    {
        $cacheKey = "insights.narratives.user.{$user->id}." . CarbonImmutable::now()->format('Y-m-d-H');

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($breakdown, $mom) {
            $apiKey = config('services.anthropic.key');
            if (! $apiKey || empty($breakdown)) {
                return $this->fallbackNarratives($breakdown, $mom);
            }

            try {
                $payload = [
                    'breakdown' => array_slice($breakdown, 0, 6),
                    'month_over_month' => $mom,
                    'currency' => 'EUR',
                ];

                $prompt = "You are a friendly personal finance coach. Given this user's monthly spending data, "
                    . "write 1 to 2 SHORT insight messages (max ~140 characters each). Use a warm tone, plain text, no markdown. "
                    . "Highlight the biggest mover or anomaly. Use the EUR symbol. Reply with ONLY a JSON array of strings.\n\n"
                    . "Data:\n" . json_encode($payload, JSON_PRETTY_PRINT);

                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => config('services.anthropic.version'),
                    'content-type' => 'application/json',
                ])->timeout(20)->post(config('services.anthropic.url'), [
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => 400,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => $prompt]],
                    ]],
                ]);

                if ($response->failed()) {
                    Log::info('Insights narrative request failed', ['status' => $response->status()]);
                    return $this->fallbackNarratives($breakdown, $mom);
                }

                $text = collect($response->json('content', []))
                    ->firstWhere('type', 'text')['text'] ?? '';

                if (preg_match('/\[.*\]/s', $text, $m)) {
                    $decoded = json_decode($m[0], true);
                    if (is_array($decoded)) {
                        $clean = array_values(array_filter(array_map(
                            fn ($s) => is_string($s) ? trim($s) : null,
                            $decoded
                        )));
                        if (! empty($clean)) {
                            return array_slice($clean, 0, 2);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::info('Insights narrative threw: '.$e->getMessage());
            }

            return $this->fallbackNarratives($breakdown, $mom);
        });
    }

    /**
     * @return array{ category: string, label: string, color: string, limit: float, spent: float, pct_used: float, status: string }[]
     */
    public function getBudgetStatus(User $user): array
    {
        $breakdown = collect($this->getMonthlyBreakdown($user))->keyBy('category');
        $budgets = CategoryBudget::query()->where('user_id', $user->id)->get();

        return $budgets->map(function (CategoryBudget $b) use ($breakdown) {
            $cat = SpendingCategories::normalize($b->category);
            $limit = (float) $b->monthly_limit;
            $spent = (float) ($breakdown[$cat]['total'] ?? 0);
            $pct   = $limit > 0 ? round(($spent / $limit) * 100, 1) : 0.0;

            $status = 'ok';
            if ($pct >= 100) {
                $status = 'over';
            } elseif ($pct >= 80) {
                $status = 'warning';
            }

            $meta = SpendingCategories::META[$cat];

            return [
                'id' => $b->id,
                'category' => $cat,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'limit' => round($limit, 2),
                'spent' => round($spent, 2),
                'pct_used' => $pct,
                'status' => $status,
            ];
        })->sortByDesc('pct_used')->values()->all();
    }

    /**
     * Trend data: per-category totals for the last $months months, oldest first.
     *
     * @return array{ months: string[], series: array<string, array{ label: string, color: string, totals: float[] }> }
     */
    public function getTimeline(User $user, int $months = 6): array
    {
        $months = max(1, min(12, $months));
        $now    = CarbonImmutable::now()->startOfMonth();

        $monthLabels = [];
        $perMonth    = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $now->subMonthsNoOverflow($i);
            $monthLabels[] = $month->format('Y-m');
            $perMonth[]    = collect($this->getMonthlyBreakdown($user, $month))->keyBy('category');
        }

        $allCategories = collect($perMonth)
            ->flatMap(fn ($c) => $c->keys())
            ->unique()
            ->values();

        $series = [];
        foreach ($allCategories as $cat) {
            $meta = SpendingCategories::META[$cat] ?? SpendingCategories::META[SpendingCategories::OTHER];
            $totals = [];
            foreach ($perMonth as $monthBreakdown) {
                $totals[] = round((float) ($monthBreakdown[$cat]['total'] ?? 0), 2);
            }
            $series[$cat] = [
                'label' => $meta['label'],
                'color' => $meta['color'],
                'totals' => $totals,
            ];
        }

        return [
            'months' => $monthLabels,
            'series' => $series,
        ];
    }

    /**
     * Deterministic narratives used when Claude is unavailable.
     *
     * @return string[]
     */
    private function fallbackNarratives(array $breakdown, array $mom): array
    {
        if (empty($breakdown)) {
            return ['Scan your first receipt to start seeing spending insights.'];
        }

        $top = $breakdown[0];
        $messages = [
            sprintf('Top category this month: %s with €%.2f.', $top['label'], $top['total']),
        ];

        $biggestMover = null;
        $biggestPct = 0.0;
        foreach ($mom as $cat => $row) {
            if ($row['delta_pct'] !== null && abs($row['delta_pct']) > abs($biggestPct)) {
                $biggestPct = $row['delta_pct'];
                $biggestMover = $cat;
            }
        }

        if ($biggestMover !== null) {
            $meta = SpendingCategories::META[$biggestMover] ?? SpendingCategories::META[SpendingCategories::OTHER];
            $direction = $biggestPct >= 0 ? '+' : '';
            $messages[] = sprintf(
                '%s is %s%.1f%% vs last month (€%.2f).',
                $meta['label'],
                $direction,
                $biggestPct,
                $mom[$biggestMover]['current']
            );
        }

        return array_slice($messages, 0, 2);
    }
}
