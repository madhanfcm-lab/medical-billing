<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class CancelInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoices.cancel');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:255']];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to cancel an invoice.',
            'reason.min' => 'Please give a more descriptive reason (at least 5 characters).',
        ];
    }
}
