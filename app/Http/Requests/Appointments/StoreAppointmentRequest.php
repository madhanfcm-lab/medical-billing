<?php

namespace App\Http\Requests\Appointments;

use App\Models\Patient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public const DURATIONS = [10, 15, 20, 30, 45, 60, 90];

    public function authorize(): bool
    {
        return $this->user()->can('appointments.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'allow_overlap' => $this->boolean('allow_overlap'),
            'duration_minutes' => $this->input('duration_minutes') ?: 15,
        ]);
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', branch_exists('patients')->whereNull('deleted_at')],
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', Rule::in(self::DURATIONS)],
            'reason' => ['nullable', 'string', 'max:255'],
            'allow_overlap' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if ($this->input('appointment_date') === today()->toDateString() && $this->input('appointment_time') < now()->format('H:i')) {
                $v->errors()->add('appointment_time', 'That time has already passed today. Choose a later time.');
            }
            if (! Patient::query()->visibleTo($this->user())->whereKey($this->input('patient_id'))->exists()) {
                $v->errors()->add('patient_id', 'You cannot book for this patient.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Please select a patient.',
            'doctor_id.required' => 'Please select a doctor.',
            'doctor_id.exists' => 'The selected doctor is not available.',
            'appointment_date.required' => 'Please choose the appointment date.',
            'appointment_date.after_or_equal' => 'The appointment must be today or a later date.',
            'appointment_time.required' => 'Please choose the time.',
            'appointment_time.date_format' => 'Enter the time as HH:MM.',
        ];
    }

    /** The fields that belong on the appointment (not the "book anyway" tick). */
    public function details(): array
    {
        return collect($this->validated())->except('allow_overlap')->all();
    }
}
