<?php

namespace Tests\Feature;

use App\Domain\Booking\BookingRules;
use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_booking_for_the_authenticated_user(): void
    {
        $logged = null;
        Log::channel('bookings')->listen(function (MessageLogged $event) use (&$logged): void {
            $logged = $event;
        });

        $author = User::factory()->create();
        $someoneElse = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $response = $this->book($author, $room, [
            'user_id' => $someoneElse->id,
            'title' => '  Планёрка  ',
        ]);

        $response->assertCreated()
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('user_id', $author->id)
            ->assertJsonPath('title', 'Планёрка');

        $this->assertArrayNotHasKey('user', $response->json());

        $booking = Booking::query()->first();

        $this->assertNotNull($booking);
        $this->assertTrue($booking->starts_at->equalTo(Carbon::parse('2026-11-02T07:00:00Z')));
        $this->assertTrue($booking->ends_at->equalTo(Carbon::parse('2026-11-02T08:00:00Z')));

        $this->assertInstanceOf(MessageLogged::class, $logged);
        $this->assertSame('info', $logged->level);
        $this->assertSame('Booking created', $logged->message);
        $this->assertSame($booking->id, $logged->context['booking_id'] ?? null);
    }

    public function test_requires_authentication(): void
    {
        $room = $this->roomIn('Europe/Moscow');

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertUnauthorized();
    }

    public function test_rejects_missing_and_unknown_fields_with_validation_error(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);

        $this->postJson('/api/bookings', [
            'room_id' => 999,
            'title' => 'Планёрка',
            'starts_at' => 'завтра',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'starts_at']);
    }

    public function test_rejects_overlapping_booking_and_allows_back_to_back(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->book($user, $room)->assertCreated();

        $this->book($user, $room, [
            'starts_at' => '2026-11-02T10:30:00+03:00',
            'ends_at' => '2026-11-02T11:30:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'starts_at' => BookingRules::MESSAGE_OVERLAP,
            ]);

        $this->book($user, $room, [
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T12:00:00+03:00',
        ])->assertCreated();

        $this->book($user, $room, [
            'starts_at' => '2026-11-02T09:00:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ])->assertCreated();

        $this->assertSame(3, Booking::query()->where('room_id', $room->id)->count());
    }

    public function test_same_slot_in_another_room_is_allowed(): void
    {
        $user = User::factory()->create();
        $first = $this->roomIn('Europe/Moscow');
        $second = $this->roomIn('Europe/Moscow');

        $this->book($user, $first)->assertCreated();
        $this->book($user, $second)->assertCreated();
    }

    public function test_overlap_with_another_users_booking_is_rejected(): void
    {
        $room = $this->roomIn('Europe/Moscow');

        $this->book(User::factory()->create(), $room)->assertCreated();

        $this->book(User::factory()->create(), $room, [
            'starts_at' => '2026-11-02T07:30:00Z',
            'ends_at' => '2026-11-02T08:30:00Z',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_enforces_duration_and_quarter_hour_grid(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->book($user, $room, [
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:15:00+03:00',
        ])->assertCreated();

        $this->book($user, $room, [
            'starts_at' => '2026-11-02T12:00:00+03:00',
            'ends_at' => '2026-11-02T12:10:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);

        $this->book($user, $room, [
            'starts_at' => '2026-11-02T10:07:00+03:00',
            'ends_at' => '2026-11-02T11:07:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'starts_at' => BookingRules::MESSAGE_QUARTER_HOUR,
            ]);

        $this->book($user, $room, [
            'starts_at' => '2026-11-02T12:00:00+03:00',
            'ends_at' => '2026-11-02T16:00:00+03:00',
        ])->assertCreated();

        $this->book($user, $room, [
            'starts_at' => '2026-11-02T16:15:00+03:00',
            'ends_at' => '2026-11-02T20:30:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'ends_at' => BookingRules::MESSAGE_TOO_LONG,
            ]);
    }

    public function test_fits_booking_into_office_local_working_hours(): void
    {
        $user = User::factory()->create();
        $moscow = $this->roomIn('Europe/Moscow');
        $novosibirsk = $this->roomIn('Asia/Novosibirsk');

        $this->book($user, $moscow, [
            'starts_at' => '2026-11-02T09:00:00+03:00',
            'ends_at' => '2026-11-02T09:15:00+03:00',
        ])->assertCreated();

        $this->book($user, $moscow, [
            'starts_at' => '2026-11-02T19:45:00+03:00',
            'ends_at' => '2026-11-02T20:00:00+03:00',
        ])->assertCreated();

        $this->book($user, $moscow, [
            'starts_at' => '2026-11-02T08:45:00+03:00',
            'ends_at' => '2026-11-02T09:45:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'starts_at' => BookingRules::MESSAGE_OUTSIDE_HOURS,
            ]);

        $this->book($user, $moscow, [
            'starts_at' => '2026-11-02T19:00:00+03:00',
            'ends_at' => '2026-11-02T20:15:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->book($user, $novosibirsk, [
            'starts_at' => '2026-11-02T02:00:00Z',
            'ends_at' => '2026-11-02T03:00:00Z',
        ])->assertCreated();

        $this->book($user, $moscow, [
            'starts_at' => '2026-11-02T02:00:00Z',
            'ends_at' => '2026-11-02T03:00:00Z',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->book($user, $moscow, [
            'starts_at' => '2026-11-02T09:00:00+07:00',
            'ends_at' => '2026-11-02T10:00:00+07:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_allows_past_weekend_and_far_future_dates(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->book($user, $room, [
            'starts_at' => '2020-06-15T10:00:00+03:00',
            'ends_at' => '2020-06-15T11:00:00+03:00',
        ])->assertCreated();

        $this->book($user, $room, [
            'starts_at' => '2026-11-08T10:00:00+03:00',
            'ends_at' => '2026-11-08T11:00:00+03:00',
        ])->assertCreated();

        $this->book($user, $room, [
            'starts_at' => '2035-06-04T10:00:00+03:00',
            'ends_at' => '2035-06-04T11:00:00+03:00',
        ])->assertCreated();
    }

    public function test_database_constraint_violation_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        Booking::creating(function () use ($user, $room): void {
            Booking::withoutEvents(function () use ($user, $room): void {
                $booking = new Booking([
                    'room_id' => $room->id,
                    'title' => 'Параллельная',
                    'starts_at' => Carbon::parse('2026-11-02T10:00:00+03:00')->utc(),
                    'ends_at' => Carbon::parse('2026-11-02T11:00:00+03:00')->utc(),
                ]);
                $booking->user()->associate($user);
                $booking->unsetRelation('user');
                $booking->save();
            });
        });

        try {
            $this->book($user, $room)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'starts_at' => BookingRules::MESSAGE_OVERLAP,
                ]);

            $this->assertSame(0, Booking::query()->count());
        } finally {
            Booking::flushEventListeners();
        }
    }

    private function roomIn(string $timezone): Room
    {
        $office = Office::factory()->create([
            'name' => $timezone,
            'timezone' => $timezone,
        ]);

        return Room::factory()->for($office)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(User $user, Room $room, array $overrides = []): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/bookings', array_merge([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], $overrides));
    }
}
