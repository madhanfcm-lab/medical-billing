<?php

namespace App\Http\Requests\Billing;

use App\Enums\PaymentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollectPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 403 (not a validation error) for settled or cancelled invoices.
        return $this->user()->can('collect', $this->route('invoice'));
    }

    public function rules(): array
    {
        $invoice = $this->route('invoice');

        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2',
                'max:'.($invoice ? (float) $invoice->balance_amount : 0)],
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
            'amount.max' => 'Amount cannot exceed the current balance.',
            'amount.decimal' => 'Amount can have at most 2 decimal places.',
            'mode.required' => 'Select a payment mode.',
            'reference_no.required' => 'Reference number is required for non-cash payments.',
            'payment_date.before_or_equal' => 'Payment date cannot be in the future.',
        ];
    }
}
