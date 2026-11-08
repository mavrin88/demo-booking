<?php

namespace App\Domain\Booking;

use Carbon\CarbonImmutable;
use Exception;
use InvalidArgumentException;

/**
 * Абсолютный интервал брони. Часовой пояс офиса применяется только в момент проверки правил.
 */
final readonly class BookingWindow
{
    private function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public static function fromIso(string $startsAt, string $endsAt): self
    {
        $start = self::parseMoment($startsAt);
        $end = self::parseMoment($endsAt);

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('Ожидалось время в ISO 8601 со смещением.');
        }

        return new self($start, $end);
    }

    public static function parseMoment(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }

        $normalized = str_ends_with($value, 'Z')
            ? substr($value, 0, -1).'+00:00'
            : $value;

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        } catch (Exception) {
            return null;
        }

        if ($parsed === false) {
            return null;
        }

        $errors = CarbonImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        if ($parsed->format('Y-m-d\TH:i:s') !== substr($normalized, 0, 19)) {
            return null;
        }

        return $parsed->utc();
    }

    public function isPositive(): bool
    {
        return $this->endsAt->greaterThan($this->startsAt);
    }

    public function durationSeconds(): int
    {
        return $this->endsAt->getTimestamp() - $this->startsAt->getTimestamp();
    }

    public function isAlignedTo(string $timezone, int $slotMinutes): bool
    {
        return $this->isSlotBoundary($this->startsAt->timezone($timezone), $slotMinutes)
            && $this->isSlotBoundary($this->endsAt->timezone($timezone), $slotMinutes);
    }

    public function startsAtIn(string $timezone): CarbonImmutable
    {
        return $this->startsAt->timezone($timezone);
    }

    public function endsAtIn(string $timezone): CarbonImmutable
    {
        return $this->endsAt->timezone($timezone);
    }

    private function isSlotBoundary(CarbonImmutable $moment, int $slotMinutes): bool
    {
        return $moment->second === 0 && $moment->minute % $slotMinutes === 0;
    }
}
