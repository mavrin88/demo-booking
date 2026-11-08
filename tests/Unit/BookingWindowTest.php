<?php

namespace Tests\Unit;

use App\Domain\Booking\BookingWindow;
use PHPUnit\Framework\TestCase;

class BookingWindowTest extends TestCase
{
    public function test_zulu_and_numeric_offset_are_the_same_instant(): void
    {
        $fromZulu = BookingWindow::parseMoment('2026-11-02T07:00:00Z');
        $fromMoscow = BookingWindow::parseMoment('2026-11-02T10:00:00+03:00');

        $this->assertNotNull($fromZulu);
        $this->assertNotNull($fromMoscow);
        $this->assertSame($fromZulu->getTimestamp(), $fromMoscow->getTimestamp());
    }

    public function test_rejects_time_without_offset(): void
    {
        $this->assertNull(BookingWindow::parseMoment('2026-11-02T10:00:00'));
    }

    public function test_rejects_impossible_calendar_date(): void
    {
        $this->assertNull(BookingWindow::parseMoment('2026-02-31T10:00:00Z'));
    }

    public function test_duration_is_absolute_and_ignores_client_offset(): void
    {
        $window = BookingWindow::fromIso(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T08:00:00Z',
        );

        $this->assertSame(3600, $window->durationSeconds());
        $this->assertTrue($window->isPositive());
    }
}
