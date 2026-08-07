<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function normalizeResponseText(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeResponseText($item), $value);
        }

        if (!is_string($value)) {
            return $value;
        }

        return $this->normalizeTextValue($value);
    }

    protected function normalizeTextValue(string $value): string
    {
        $normalized = str_replace(["\\r\\n", "\\n", "\\r", "\r\n", "\r"], "\n", $value);
        $normalized = preg_replace('/[ \t]+$/m', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[ \t]{2,}/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
