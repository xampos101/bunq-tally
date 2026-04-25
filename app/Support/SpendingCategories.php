<?php

namespace App\Support;

/**
 * Fixed taxonomy of spending categories used for AI categorization and budgets.
 *
 * Keeping this list in code (and not in DB) makes drift impossible: anything
 * Claude returns outside this list is normalized to "other".
 */
final class SpendingCategories
{
    public const GROCERIES     = 'groceries';
    public const FOOD_OUT      = 'food_out';
    public const ALCOHOL       = 'alcohol';
    public const COFFEE        = 'coffee';
    public const TRANSPORT     = 'transport';
    public const ENTERTAINMENT = 'entertainment';
    public const UTILITIES     = 'utilities';
    public const SHOPPING      = 'shopping';
    public const HEALTH        = 'health';
    public const HOUSEHOLD     = 'household';
    public const OTHER         = 'other';

    public const CATEGORIES = [
        self::GROCERIES,
        self::FOOD_OUT,
        self::ALCOHOL,
        self::COFFEE,
        self::TRANSPORT,
        self::ENTERTAINMENT,
        self::UTILITIES,
        self::SHOPPING,
        self::HEALTH,
        self::HOUSEHOLD,
        self::OTHER,
    ];

    /**
     * Display labels and brand colors for the frontend (kept here so backend
     * insight messages and seeders can share them).
     */
    public const META = [
        self::GROCERIES     => ['label' => 'Groceries',     'color' => '#00D17A', 'icon' => 'shopping-basket'],
        self::FOOD_OUT      => ['label' => 'Food out',      'color' => '#FF9500', 'icon' => 'utensils'],
        self::ALCOHOL       => ['label' => 'Alcohol',       'color' => '#AF52DE', 'icon' => 'beer'],
        self::COFFEE        => ['label' => 'Coffee',        'color' => '#A2845E', 'icon' => 'coffee'],
        self::TRANSPORT     => ['label' => 'Transport',     'color' => '#0A84FF', 'icon' => 'car'],
        self::ENTERTAINMENT => ['label' => 'Entertainment', 'color' => '#FF2D55', 'icon' => 'film'],
        self::UTILITIES     => ['label' => 'Utilities',     'color' => '#5AC8FA', 'icon' => 'plug'],
        self::SHOPPING      => ['label' => 'Shopping',      'color' => '#FF3B30', 'icon' => 'shopping-bag'],
        self::HEALTH        => ['label' => 'Health',        'color' => '#34C759', 'icon' => 'heart'],
        self::HOUSEHOLD     => ['label' => 'Household',     'color' => '#FFCC00', 'icon' => 'home'],
        self::OTHER         => ['label' => 'Other',         'color' => '#8E8E93', 'icon' => 'tag'],
    ];

    /**
     * Normalize an arbitrary string into a valid category, defaulting to "other".
     */
    public static function normalize(?string $candidate): string
    {
        if (! is_string($candidate) || $candidate === '') {
            return self::OTHER;
        }

        $key = strtolower(trim($candidate));
        $key = str_replace([' ', '-'], '_', $key);

        return in_array($key, self::CATEGORIES, true) ? $key : self::OTHER;
    }
}
