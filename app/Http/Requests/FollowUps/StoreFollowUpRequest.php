<?php

namespace App\Http\Requests\FollowUps;

use App\Models\Patient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('followups.manage');
    }

    protected function prepareForValidation(): void
    {
        $merge = ['follow_up_time' => $this->filled('follow_up_time') ? $this->input('follow_up_time') : null];
        // A doctor sets follow-ups under their own profile.
        if ($this->user()->hasRole('doctor')) {
            $merge['doctor_id'] = $this->user()->doctorProfile?->id;
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $patient = $this->input('patient_id');

        return [
            'patient_id' => ['required', 'integer', branch_exists('patients')->whereNull('deleted_at')],
            'visit_id' => ['nullable', 'integer', branch_exists('visits')->where('patient_id', $patient)],
            'emi_plan_id' => ['nullable', 'integer', branch_exists('emi_plans')->where('patient_id', $patient)],
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'follow_up_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'follow_up_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isEmpty() && ! Patient::query()->visibleTo($this->user())->whereKey($this->input('patient_id'))->exists()) {
                $v->errors()->add('patient_id', 'You can only set follow-ups for your own patients.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Please select a patient.',
            'visit_id.exists' => 'The selected visit does not belong to this patient.',
            'emi_plan_id.exists' => 'The selected EMI plan does not belong to this patient.',
            'doctor_id.required' => 'Please select a doctor.',
            'doctor_id.exists' => 'The selected doctor is not available.',
            'follow_up_date.required' => 'Please choose the follow-up date.',
            'follow_up_date.after_or_equal' => 'Follow-up date must be today or later.',
            'follow_up_time.date_format' => 'Enter the time as HH:MM.',
            'reason.required' => 'Please enter the reason / purpose.',
        ];
    }
}
