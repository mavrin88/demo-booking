<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_cancel_booking(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        Sanctum::actingAs($author);

        $this->deleteJson('/api/bookings/'.$booking->id)->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_only_author_can_cancel_booking(): void
    {
        $booking = Booking::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Отменить бронь может только её автор.');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_guest_cannot_cancel_booking(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson('/api/bookings/'.$booking->id)->assertUnauthorized();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_missing_booking_returns_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999999')->assertNotFound();
    }

    public function test_non_numeric_id_returns_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/abc')->assertNotFound();
    }
}
