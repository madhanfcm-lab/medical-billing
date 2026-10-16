<?php

namespace App\Http\Requests\Appointments;

use Illuminate\Support\Arr;

class UpdateAppointmentRequest extends StoreAppointmentRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('appointment'));
    }

    /** The person on an appointment does not change; book a new one for someone else. */
    public function rules(): array
    {
        return Arr::except(parent::rules(), 'patient_id');
    }

    public function after(): array
    {
        return [function ($v) {
            if ($v->errors()->isEmpty() && $this->input('appointment_date') === today()->toDateString() && $this->input('appointment_time') < now()->format('H:i')) {
                $v->errors()->add('appointment_time', 'That time has already passed today. Choose a later time.');
            }
        }];
    }
}
