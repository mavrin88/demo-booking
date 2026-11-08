<?php

namespace Tests\Unit;

use App\Domain\Booking\BookingRules;
use App\Domain\Booking\BookingWindow;
use PHPUnit\Framework\TestCase;

class BookingRulesTest extends TestCase
{
    public function test_accepts_booking_inside_moscow_hours_on_quarter_hour_grid(): void
    {
        $this->assertSame([], $this->violations(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        ));
    }

    public function test_accepts_four_hours_and_rejects_fifteen_minutes_more(): void
    {
        $this->assertSame([], $this->violations(
            '2026-11-02T09:00:00+03:00',
            '2026-11-02T13:00:00+03:00',
        ));

        $errors = $this->violations(
            '2026-11-02T09:00:00+03:00',
            '2026-11-02T13:15:00+03:00',
        );

        $this->assertSame([BookingRules::MESSAGE_TOO_LONG], $errors['ends_at']);
    }

    public function test_accepts_fifteen_minutes_and_rejects_shorter_unaligned_slot(): void
    {
        $this->assertSame([], $this->violations(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T10:15:00+03:00',
        ));

        $errors = $this->violations(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T10:10:00+03:00',
        );

        $this->assertContains(BookingRules::MESSAGE_QUARTER_HOUR, $errors['starts_at']);
        $this->assertSame([BookingRules::MESSAGE_TOO_SHORT], $errors['ends_at']);
    }

    public function test_uses_office_timezone_rather_than_the_client_offset(): void
    {
        $start = '2026-11-02T02:00:00Z';
        $end = '2026-11-02T03:00:00Z';

        $this->assertSame([], $this->violations($start, $end, 'Asia/Novosibirsk'));
        $this->assertSame(
            [BookingRules::MESSAGE_OUTSIDE_HOURS],
            $this->violations($start, $end, 'Europe/Moscow')['starts_at'],
        );
    }

    public function test_closing_time_is_inclusive_and_the_next_slot_is_not(): void
    {
        $this->assertSame([], $this->violations(
            '2026-11-02T19:00:00+03:00',
            '2026-11-02T20:00:00+03:00',
        ));

        $errors = $this->violations(
            '2026-11-02T19:00:00+03:00',
            '2026-11-02T20:15:00+03:00',
        );

        $this->assertSame([BookingRules::MESSAGE_OUTSIDE_HOURS], $errors['starts_at']);
    }

    public function test_opening_time_is_inclusive(): void
    {
        $this->assertSame([], $this->violations(
            '2026-11-02T09:00:00+07:00',
            '2026-11-02T09:15:00+07:00',
            'Asia/Novosibirsk',
        ));

        $errors = $this->violations(
            '2026-11-02T08:45:00+07:00',
            '2026-11-02T09:00:00+07:00',
            'Asia/Novosibirsk',
        );

        $this->assertSame([BookingRules::MESSAGE_OUTSIDE_HOURS], $errors['starts_at']);
    }

    public function test_rejects_interval_that_leaves_the_local_day(): void
    {
        $errors = $this->violations(
            '2026-11-02T23:00:00+03:00',
            '2026-11-03T01:00:00+03:00',
        );

        $this->assertSame([BookingRules::MESSAGE_OUTSIDE_HOURS], $errors['starts_at']);
    }

    public function test_rejects_end_before_start(): void
    {
        $errors = $this->violations(
            '2026-11-02T11:00:00+03:00',
            '2026-11-02T10:00:00+03:00',
        );

        $this->assertSame([
            'ends_at' => [BookingRules::MESSAGE_ENDS_BEFORE_START],
        ], $errors);
    }

    /**
     * @return array<string, list<string>>
     */
    private function violations(string $startsAt, string $endsAt, string $timezone = 'Europe/Moscow'): array
    {
        return (new BookingRules)->violations(
            BookingWindow::fromIso($startsAt, $endsAt),
            $timezone,
        );
    }
}
