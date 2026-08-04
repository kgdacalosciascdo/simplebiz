<?php

namespace App\Support;

final class RegistryNormalizer
{
    public static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/\s+/u', ' ', trim($value));

        return $value === '' ? null : mb_strtolower($value);
    }

    public static function code(?string $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : preg_replace('/[^a-z0-9]+/i', '', $value);
    }

    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/[^0-9+]/', '', $value);

        return $value === '' ? null : $value;
    }
}
