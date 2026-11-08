<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingOverlapConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_overlapping_intervals_for_the_same_room(): void
    {
        $room = Room::factory()->create();

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        try {
            Booking::factory()->for($room)->create([
                'starts_at' => '2026-11-02 07:30:00',
                'ends_at' => '2026-11-02 08:30:00',
            ]);
            $this->fail('Исключающее ограничение должно отклонить пересечение.');
        } catch (QueryException $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'bookings_no_overlap'),
                $exception->getMessage(),
            );
        }
    }

    public function test_database_allows_back_to_back_intervals_and_same_slot_in_another_room(): void
    {
        $room = Room::factory()->create();
        $otherRoom = Room::factory()->create();

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 08:00:00',
            'ends_at' => '2026-11-02 09:00:00',
        ]);

        Booking::factory()->for($otherRoom)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        $this->assertSame(2, Booking::query()->where('room_id', $room->id)->count());
        $this->assertSame(1, Booking::query()->where('room_id', $otherRoom->id)->count());
    }
}
