<?php

namespace App\Http\Controllers;

use App\Models\CategoryBudget;
use App\Models\User;
use App\Support\SpendingCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryBudgetController extends Controller
{
    public function index(): JsonResponse
    {
        $user = User::query()->first();
        if (! $user) {
            return response()->json(['data' => []]);
        }

        $budgets = CategoryBudget::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->get()
            ->map(fn (CategoryBudget $b) => $this->transform($b));

        return response()->json(['data' => $budgets]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = User::query()->firstOrFail();

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(SpendingCategories::CATEGORIES)],
            'monthly_limit' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $budget = CategoryBudget::updateOrCreate(
            ['user_id' => $user->id, 'category' => $data['category']],
            [
                'monthly_limit' => $data['monthly_limit'],
                'currency' => $data['currency'] ?? 'EUR',
            ]
        );

        return response()->json(['data' => $this->transform($budget)], 201);
    }

    public function update(Request $request, CategoryBudget $budget): JsonResponse
    {
        $data = $request->validate([
            'monthly_limit' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $budget->update($data);

        return response()->json(['data' => $this->transform($budget->fresh())]);
    }

    public function destroy(CategoryBudget $budget): JsonResponse
    {
        $budget->delete();
        return response()->json(['ok' => true]);
    }

    private function transform(CategoryBudget $b): array
    {
        $cat = SpendingCategories::normalize($b->category);
        $meta = SpendingCategories::META[$cat];
        return [
            'id' => $b->id,
            'category' => $cat,
            'label' => $meta['label'],
            'color' => $meta['color'],
            'monthly_limit' => (float) $b->monthly_limit,
            'currency' => $b->currency,
        ];
    }
}
