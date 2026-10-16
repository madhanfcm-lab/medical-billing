<?php

namespace App\Http\Requests\Emi;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleEmiRequest extends FormRequest
{
    use ValidatesEmiSchedule;

    public function authorize(): bool
    {
        return $this->user()->can('reschedule', $this->route('emiPlan'));
    }

    public function rules(): array
    {
        // Admins may pick any start date, as when creating a plan.
        return $this->scheduleRules(startMustNotBePast: false) + ['reason' => ['required', 'string', 'min:5', 'max:255']];
    }

    public function messages(): array
    {
        return $this->scheduleMessages() + ['reason.required' => 'Give a reason for rescheduling.', 'reason.min' => 'Please give a more descriptive reason (at least 5 characters).'];
    }
}
