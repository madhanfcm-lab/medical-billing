<?php

namespace App\Http\Requests\Emi;

use Illuminate\Foundation\Http\FormRequest;

class CancelEmiPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cancel', $this->route('emiPlan'));
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:255']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'A reason is required to cancel an EMI plan.', 'reason.min' => 'Please give a more descriptive reason (at least 5 characters).'];
    }
}
