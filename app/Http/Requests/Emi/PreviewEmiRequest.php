<?php

namespace App\Http\Requests\Emi;

use Illuminate\Foundation\Http\FormRequest;

/** Lets the form show the schedule before saving. `amount` is what the schedule must cover. */
class PreviewEmiRequest extends FormRequest
{
    use ValidatesEmiSchedule;

    public function authorize(): bool
    {
        return $this->user()->can('emi.manage');
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99']]
            + $this->scheduleRules(startMustNotBePast: false);
    }

    public function messages(): array
    {
        return $this->scheduleMessages() + ['amount.required' => 'Enter the amount to be financed.', 'amount.min' => 'Nothing to schedule: amount must be above zero.'];
    }
}
