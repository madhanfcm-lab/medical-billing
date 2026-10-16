<?php

namespace App\Http\Requests\Appointments;

use App\Enums\ConfirmationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('appointment'));
    }

    public function rules(): array
    {
        return [
            'via' => ['required', Rule::enum(ConfirmationMode::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['via.required' => 'Tell us how the patient confirmed (phone, WhatsApp…).'];
    }
}
