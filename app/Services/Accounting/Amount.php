<?php

namespace App\Services\Accounting;

use Illuminate\Validation\ValidationException;

final class Amount
{
    public static function units(mixed $value): int
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,11}(?:\.\d{1,4})?$/D', $value)) {
            throw ValidationException::withMessages(['lines' => 'Amounts must be non-negative, with up to 11 whole digits and 4 decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return ((int) $whole * 10000) + (int) str_pad($fraction, 4, '0');
    }

    public static function decimal(int $units): string
    {
        return ($units < 0 ? '-' : '').intdiv(abs($units), 10000).'.'.str_pad((string) (abs($units) % 10000), 4, '0', STR_PAD_LEFT);
    }
}
