<?php

namespace App\Http\Requests;

use App\Domain\Booking\BookingRules;
use App\Domain\Booking\BookingWindow;
use App\Models\Room;
use App\Models\User;
use App\Rules\Iso8601DateTime;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    private ?Room $room = null;

    private ?BookingWindow $window = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', new Iso8601DateTime],
            'ends_at' => ['required', new Iso8601DateTime],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'Комната указана неверно.',
            'room_id.exists' => 'Комната не найдена.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно быть длиннее 255 символов.',
            'starts_at.required' => 'Укажите время начала.',
            'ends_at.required' => 'Укажите время окончания.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $timezone = $this->room()->office->timezone;

                foreach ((new BookingRules)->violations($this->bookingWindow(), $timezone) as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    public function author(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    public function room(): Room
    {
        return $this->room ??= Room::query()
            ->with('office')
            ->findOrFail($this->integer('room_id'));
    }

    public function bookingWindow(): BookingWindow
    {
        return $this->window ??= BookingWindow::fromIso(
            $this->string('starts_at')->toString(),
            $this->string('ends_at')->toString(),
        );
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');

        if (is_string($title)) {
            $this->merge([
                'title' => trim($title),
            ]);
        }
    }
}
