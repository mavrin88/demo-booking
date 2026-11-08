<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookingPolicy
{
    public function delete(User $user, Booking $booking): Response
    {
        if ($booking->user_id !== $user->id) {
            return Response::deny('Отменить бронь может только её автор.');
        }

        return Response::allow();
    }
}
