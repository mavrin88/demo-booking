<?php

namespace App\Rules;

use App\Domain\Booking\BookingWindow;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class Iso8601DateTime implements ValidationRule
{
    public const MESSAGE = 'Время должно быть в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || BookingWindow::parseMoment($value) === null) {
            $fail(self::MESSAGE);
        }
    }
}
