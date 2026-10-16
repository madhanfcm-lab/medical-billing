<?php

namespace App\Http\Requests\Billing;

use App\Enums\PaymentMode;
use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivePatientPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $this->user()->can('payments.collect')
            && Patient::query()->visibleTo($this->user())->whereKey($patient->getKey())->exists();
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'mode' => ['required', Rule::enum(PaymentMode::class)],
            'reference_no' => [Rule::requiredIf(fn () => PaymentMode::tryFrom((string) $this->input('mode'))?->requiresReference() ?? false), 'nullable', 'string', 'max:100'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the amount received.',
            'amount.min' => 'Amount must be at least ₹0.01.',
            'amount.decimal' => 'Amount can have at most 2 decimal places.',
            'mode.required' => 'Select a payment mode.',
            'reference_no.required' => 'Reference number is required for non-cash payments.',
            'payment_date.before_or_equal' => 'Payment date cannot be in the future.',
        ];
    }
}
