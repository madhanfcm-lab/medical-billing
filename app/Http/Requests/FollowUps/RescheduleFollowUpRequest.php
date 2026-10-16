<?php

namespace App\Http\Requests\FollowUps;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('followUp'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['follow_up_time' => $this->filled('follow_up_time') ? $this->input('follow_up_time') : null]);
    }

    public function rules(): array
    {
        return [
            'follow_up_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'follow_up_time' => ['nullable', 'date_format:H:i'],
            'doctor_id' => ['nullable', 'integer', Rule::exists('doctors', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'follow_up_date.required' => 'Please choose the new date.',
            'follow_up_date.after_or_equal' => 'The new date must be today or later.',
            'follow_up_time.date_format' => 'Enter the time as HH:MM.',
        ];
    }
}
