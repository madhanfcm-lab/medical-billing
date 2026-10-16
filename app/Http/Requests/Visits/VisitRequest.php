<?php

namespace App\Http\Requests\Visits;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('visits.manage');
    }

    protected function prepareForValidation(): void
    {
        // A doctor can only record visits under their own profile.
        if ($this->user()->hasRole('doctor')) {
            $this->merge(['doctor_id' => $this->user()->doctorProfile?->id]);
        }
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', branch_exists('patients')->whereNull('deleted_at')],
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'visit_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'follow_up_id' => ['nullable', 'integer', branch_exists('follow_ups')->where('patient_id', $this->input('patient_id'))],
            'chief_complaint' => ['required', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'treatment_given' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Please select a patient.',
            'patient_id.exists' => 'The selected patient was not found.',
            'doctor_id.required' => 'Please select a doctor.',
            'doctor_id.exists' => 'The selected doctor is not available.',
            'visit_date.required' => 'Please enter the visit date.',
            'visit_date.before_or_equal' => 'Visit date cannot be in the future.',
            'chief_complaint.required' => 'Please enter the chief complaint.',
        ];
    }
}
