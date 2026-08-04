<?php

namespace App\Support;

final class CurrencyCatalog
{
    public static function get(string $code): ?array
    {
        $currencies = ['PHP' => ['Philippine Peso', '₱', 2], 'USD' => ['US Dollar', '$', 2], 'EUR' => ['Euro', '€', 2], 'GBP' => ['Pound Sterling', '£', 2], 'JPY' => ['Japanese Yen', '¥', 0], 'CNY' => ['Yuan Renminbi', '¥', 2], 'SGD' => ['Singapore Dollar', 'S$', 2], 'AUD' => ['Australian Dollar', 'A$', 2], 'CAD' => ['Canadian Dollar', 'C$', 2], 'HKD' => ['Hong Kong Dollar', 'HK$', 2], 'NZD' => ['New Zealand Dollar', 'NZ$', 2], 'INR' => ['Indian Rupee', '₹', 2], 'KRW' => ['South Korean Won', '₩', 0], 'MYR' => ['Malaysian Ringgit', 'RM', 2], 'THB' => ['Thai Baht', '฿', 2], 'IDR' => ['Indonesian Rupiah', 'Rp', 2], 'VND' => ['Vietnamese Dong', '₫', 0], 'CHF' => ['Swiss Franc', 'CHF', 2], 'AED' => ['UAE Dirham', 'د.إ', 2], 'SAR' => ['Saudi Riyal', '﷼', 2]];

        return $currencies[strtoupper($code)] ?? null;
    }
}
