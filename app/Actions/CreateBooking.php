<?php

namespace App\Actions;

use App\Domain\Booking\BookingRules;
use App\Domain\Booking\BookingWindow;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Создаёт бронь в транзакции.
 *
 * Строка комнаты блокируется через FOR UPDATE, чтобы два параллельных запроса
 * на одну комнату сериализовались. Исключающее ограничение bookings_no_overlap
 * остаётся страховкой для вставок, которые обойдут эту проверку.
 */
final class CreateBooking
{
    public function handle(User $author, Room $room, string $title, BookingWindow $window): Booking
    {
        try {
            $booking = DB::transaction(function () use ($author, $room, $title, $window): Booking {
                $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->first();

                if ($lockedRoom === null) {
                    throw ValidationException::withMessages([
                        'room_id' => 'Комната не найдена.',
                    ]);
                }

                $overlaps = Booking::query()
                    ->overlapping($room->id, $window->startsAt, $window->endsAt)
                    ->exists();

                if ($overlaps) {
                    throw ValidationException::withMessages([
                        'starts_at' => BookingRules::MESSAGE_OVERLAP,
                    ]);
                }

                $booking = new Booking([
                    'room_id' => $room->id,
                    'title' => $title,
                    'starts_at' => $window->startsAt,
                    'ends_at' => $window->endsAt,
                ]);
                $booking->user()->associate($author);
                $booking->unsetRelation('user');
                $booking->save();

                return $booking;
            });
        } catch (QueryException $exception) {
            if ($this->isOverlapViolation($exception)) {
                throw ValidationException::withMessages([
                    'starts_at' => BookingRules::MESSAGE_OVERLAP,
                ]);
            }

            throw $exception;
        }

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
        ]);

        return $booking;
    }

    private function isOverlapViolation(QueryException $exception): bool
    {
        $codes = [
            (string) $exception->getCode(),
            isset($exception->errorInfo[0]) ? (string) $exception->errorInfo[0] : null,
            $exception->getPrevious() !== null ? (string) $exception->getPrevious()->getCode() : null,
        ];

        if (in_array('23P01', $codes, true)) {
            return true;
        }

        return str_contains($exception->getMessage(), 'bookings_no_overlap');
    }
}
