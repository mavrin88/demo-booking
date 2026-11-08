<?php

namespace App\Domain\Booking;

/**
 * Правила одной брони, которые не зависят от других броней комнаты.
 */
final class BookingRules
{
    public const OPENING_HOUR = 9;

    public const CLOSING_HOUR = 20;

    public const SLOT_MINUTES = 15;

    public const MIN_DURATION_SECONDS = 15 * 60;

    public const MAX_DURATION_SECONDS = 4 * 60 * 60;

    public const MESSAGE_ENDS_BEFORE_START = 'Время окончания должно быть позже времени начала.';

    public const MESSAGE_QUARTER_HOUR = 'Время начала и окончания должно быть кратно 15 минутам по местному времени офиса (10:00, 10:15, 10:30, 10:45).';

    public const MESSAGE_TOO_SHORT = 'Длительность брони должна быть не меньше 15 минут.';

    public const MESSAGE_TOO_LONG = 'Длительность брони должна быть не больше 4 часов.';

    public const MESSAGE_OUTSIDE_HOURS = 'Бронь должна целиком укладываться в рабочее время офиса с 09:00 до 20:00.';

    public const MESSAGE_OVERLAP = 'Комната уже забронирована на это время.';

    /**
     * @return array<string, list<string>>
     */
    public function violations(BookingWindow $window, string $timezone): array
    {
        if (! $window->isPositive()) {
            return [
                'ends_at' => [self::MESSAGE_ENDS_BEFORE_START],
            ];
        }

        $errors = [];

        if (! $window->isAlignedTo($timezone, self::SLOT_MINUTES)) {
            $errors['starts_at'][] = self::MESSAGE_QUARTER_HOUR;
        }

        $seconds = $window->durationSeconds();

        if ($seconds < self::MIN_DURATION_SECONDS) {
            $errors['ends_at'][] = self::MESSAGE_TOO_SHORT;
        }

        if ($seconds > self::MAX_DURATION_SECONDS) {
            $errors['ends_at'][] = self::MESSAGE_TOO_LONG;
        }

        if (! $this->fitsOfficeHours($window, $timezone)) {
            $errors['starts_at'][] = self::MESSAGE_OUTSIDE_HOURS;
        }

        return $errors;
    }

    private function fitsOfficeHours(BookingWindow $window, string $timezone): bool
    {
        $start = $window->startsAtIn($timezone);
        $end = $window->endsAtIn($timezone);

        if (! $start->isSameDay($end)) {
            return false;
        }

        $opensAt = $start->setTime(self::OPENING_HOUR, 0);
        $closesAt = $start->setTime(self::CLOSING_HOUR, 0);

        return $start->greaterThanOrEqualTo($opensAt) && $end->lessThanOrEqualTo($closesAt);
    }
}
