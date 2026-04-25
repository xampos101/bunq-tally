<?php

namespace Database\Seeders;

use App\Models\CategoryBudget;
use App\Models\Receipt;
use App\Models\User;
use App\Support\SpendingCategories;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeds two months of categorized receipts plus a few sample budgets so the
 * Insights / AI Spending Coach screen has rich demo data without needing OCR.
 *
 * Run with:  php artisan db:seed --class=InsightsDemoSeeder
 */
class InsightsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first()
            ?? User::factory()->create([
                'name' => 'Demo',
                'email' => 'demo@buqntally.test',
                'password' => bcrypt('password'),
            ]);

        $now = CarbonImmutable::now()->startOfMonth();
        $prev = $now->subMonthNoOverflow();

        // Month -1: previous month baseline
        $this->createReceipt($user, $prev->addDays(2),  'Albert Heijn',     'EUR', SpendingCategories::GROCERIES, [
            ['Bananas', 1.49], ['Milk', 1.20], ['Bread', 2.30], ['Eggs', 3.10], ['Apples', 2.40],
        ]);
        $this->createReceipt($user, $prev->addDays(7),  'Cafe Loca',         'EUR', SpendingCategories::COFFEE, [
            ['Cappuccino', 3.80], ['Croissant', 2.50],
        ]);
        $this->createReceipt($user, $prev->addDays(11), 'GVB',               'EUR', SpendingCategories::TRANSPORT, [
            ['Tram ticket', 3.40], ['Metro day pass', 8.50],
        ]);
        $this->createReceipt($user, $prev->addDays(15), 'Pathe',             'EUR', SpendingCategories::ENTERTAINMENT, [
            ['Movie ticket', 12.50], ['Popcorn combo', 8.00],
        ]);
        $this->createReceipt($user, $prev->addDays(19), 'Bar Bukowski',      'EUR', SpendingCategories::ALCOHOL, [
            ['Amstel 0.3', 4.50], ['IPA pint', 6.50], ['Wine glass', 7.00],
        ]);
        $this->createReceipt($user, $prev->addDays(23), 'Hema',              'EUR', SpendingCategories::SHOPPING, [
            ['Socks 3-pack', 7.99], ['Notebook', 4.50],
        ]);

        // Current month: heavier spend, drives interesting deltas + budget warnings
        $this->createReceipt($user, $now->addDays(1),  'Albert Heijn',       'EUR', SpendingCategories::GROCERIES, [
            ['Pasta', 1.20], ['Tomato sauce', 2.10], ['Cheese', 5.20], ['Yoghurt', 3.40], ['Salad mix', 2.80],
        ]);
        $this->createReceipt($user, $now->addDays(3),  'Lidl',               'EUR', SpendingCategories::GROCERIES, [
            ['Chicken breast', 6.50], ['Rice 1kg', 1.99], ['Olive oil', 4.99], ['Bell peppers', 3.20],
        ]);
        $this->createReceipt($user, $now->addDays(5),  'Starbucks',          'EUR', SpendingCategories::COFFEE, [
            ['Latte', 4.20], ['Almond cookie', 2.80],
        ]);
        $this->createReceipt($user, $now->addDays(6),  'Cafe Loca',          'EUR', SpendingCategories::COFFEE, [
            ['Flat white', 3.90], ['Espresso', 2.50],
        ]);
        $this->createReceipt($user, $now->addDays(8),  'Sushi Time',         'EUR', SpendingCategories::FOOD_OUT, [
            ['Salmon set', 14.50], ['Edamame', 4.00], ['Miso soup', 3.50],
        ]);
        $this->createReceipt($user, $now->addDays(10), 'Burger Bar',         'EUR', SpendingCategories::FOOD_OUT, [
            ['Cheeseburger', 11.00], ['Fries', 4.00], ['Cola', 2.50],
        ]);
        $this->createReceipt($user, $now->addDays(12), 'Bar Bukowski',       'EUR', SpendingCategories::ALCOHOL, [
            ['Heineken pint', 5.50], ['Wine glass', 7.00], ['G&T', 9.00], ['Tequila shot', 6.00],
        ]);
        $this->createReceipt($user, $now->addDays(13), 'Liquor Store',       'EUR', SpendingCategories::ALCOHOL, [
            ['Red wine bottle', 12.00], ['Craft IPA 6-pack', 11.50],
        ]);
        $this->createReceipt($user, $now->addDays(15), 'NS',                 'EUR', SpendingCategories::TRANSPORT, [
            ['Train Amsterdam-Utrecht', 8.40], ['Train return', 8.40],
        ]);
        $this->createReceipt($user, $now->addDays(17), 'Etos',               'EUR', SpendingCategories::HEALTH, [
            ['Vitamin C', 5.99], ['Toothpaste', 3.50],
        ]);
        $this->createReceipt($user, $now->addDays(19), 'IKEA',               'EUR', SpendingCategories::HOUSEHOLD, [
            ['Candles', 4.99], ['Cushion cover', 9.99], ['Plant pot', 6.50],
        ]);

        // Budgets — current month spend trips Coffee (warning) and Alcohol (over).
        foreach ([
            [SpendingCategories::GROCERIES,     250],
            [SpendingCategories::FOOD_OUT,      120],
            [SpendingCategories::COFFEE,         15],
            [SpendingCategories::ALCOHOL,        40],
            [SpendingCategories::ENTERTAINMENT,  60],
        ] as [$category, $limit]) {
            CategoryBudget::updateOrCreate(
                ['user_id' => $user->id, 'category' => $category],
                ['monthly_limit' => $limit, 'currency' => 'EUR'],
            );
        }
    }

    /**
     * @param  array<int, array{0: string, 1: float}>  $items
     */
    private function createReceipt(
        User $user,
        CarbonImmutable $date,
        string $store,
        string $currency,
        string $category,
        array $items,
    ): void {
        $total = array_sum(array_column($items, 1));

        $receipt = Receipt::create([
            'user_id' => $user->id,
            'store' => $store,
            'receipt_image_path' => null,
            'total_price' => round($total, 2),
            'currency' => $currency,
            'purchased_at' => $date,
        ]);

        foreach ($items as [$name, $price]) {
            $receipt->items()->create([
                'item_name' => $name,
                'price' => $price,
                'quantity' => 1,
                'category' => $category,
                'category_confidence' => 0.95,
            ]);
        }
    }
}
