<?php

namespace App\Http\Requests\Billing;

use App\Enums\PaymentMode;
use App\Services\InvoiceCalculator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoices.create');
    }

    protected function prepareForValidation(): void
    {
        $blank = fn ($v) => $v === null || $v === '';
        $this->merge([
            'create_visit' => $this->boolean('create_visit'),
            'pay_amount' => $blank($this->input('pay_amount')) ? 0 : $this->input('pay_amount'),
            'discount_value' => $blank($this->input('discount_value')) ? 0 : $this->input('discount_value'),
            'items' => collect($this->input('items', []))->filter(fn ($i) => is_array($i))->map(fn ($i) => array_merge($i, [
                'discount_amount' => $blank($i['discount_amount'] ?? null) ? 0 : $i['discount_amount'],
                'gst_percent' => $blank($i['gst_percent'] ?? null) ? 0 : $i['gst_percent'],
            ]))->values()->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', branch_exists('patients')->whereNull('deleted_at')],
            'visit_id' => ['nullable', 'integer', branch_exists('visits')->where('patient_id', $this->input('patient_id'))],
            'doctor_id' => ['nullable', 'integer', Rule::exists('doctors', 'id')->whereNull('deleted_at')],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'return_to' => ['nullable', 'in:patient'],
            'create_visit' => ['boolean'],
            'visit_complaint' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['nullable', 'string', 'max:200'],
            'items.*.doctor_fee' => ['nullable', 'boolean'],
            'items.*.service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0.01', 'max:9999999.99', 'decimal:0,2'],
            'items.*.discount_amount' => ['numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'items.*.gst_percent' => ['numeric', 'min:0', 'max:100', 'decimal:0,2'],

            'discount_type' => ['nullable', Rule::in([InvoiceCalculator::DISCOUNT_AMOUNT, InvoiceCalculator::DISCOUNT_PERCENT])],
            'discount_value' => ['numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'discount_reason' => ['nullable', 'string', 'max:255'],

            'pay_amount' => ['numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'pay_mode' => ['nullable', Rule::enum(PaymentMode::class)],
            'pay_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** Cross-field rules that need the calculated totals. */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if (collect($this->input('items'))->contains(fn ($i) => ! empty($i['doctor_fee'])) && blank($this->input('doctor_id'))) {
                $v->errors()->add('doctor_id', 'Choose the doctor whose consultation fee is on the bill.');

                return;
            }

            if ($this->boolean('create_visit') && blank($this->input('visit_id')) && blank($this->input('doctor_id'))) {
                $v->errors()->add('doctor_id', 'Choose the doctor to record this visit.');

                return;
            }

            $items = $this->input('items');
            foreach ($items as $i => $item) {
                if (to_paise($item['discount_amount']) > (int) $item['quantity'] * to_paise($item['unit_price'])) {
                    $v->errors()->add("items.$i.discount_amount", 'Item discount cannot exceed the item amount (row '.($i + 1).').');
                }
            }
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            try {
                $calc = app(InvoiceCalculator::class)->calculate($items, $this->input('discount_type'), $this->input('discount_value'));
            } catch (InvalidArgumentException $e) {
                $v->errors()->add('discount_value', $e->getMessage());

                return;
            }

            $hasDiscount = to_paise($calc['discount_amount']) > 0;
            if ($hasDiscount && blank($this->input('discount_reason'))) {
                $v->errors()->add('discount_reason', 'Please give a reason for the discount.');
            }

            $pay = to_paise($this->input('pay_amount'));
            if ($pay > to_paise($calc['grand_total'])) {
                $v->errors()->add('pay_amount', 'Payment cannot exceed the grand total of '.money($calc['grand_total']).'.');
            }
            if ($pay > 0) {
                $mode = PaymentMode::tryFrom((string) $this->input('pay_mode'));
                if (! $mode) {
                    $v->errors()->add('pay_mode', 'Select a payment mode.');
                } elseif ($mode->requiresReference() && blank($this->input('pay_reference'))) {
                    $v->errors()->add('pay_reference', 'Reference number is required for '.$mode->label().' payments.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Please select a patient.',
            'visit_id.exists' => 'The selected visit does not belong to this patient.',
            'doctor_id.exists' => 'The selected doctor is not available.',
            'invoice_date.before_or_equal' => 'Invoice date cannot be in the future.',
            'items.required' => 'Add at least one service to the bill.',
            'items.min' => 'Add at least one service to the bill.',
            'items.*.service_id.required' => 'Select a service on every row.',
            'items.*.service_id.exists' => 'A selected service is no longer available.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
            'items.*.unit_price.min' => 'Price must be at least ₹0.01.',
            'items.*.unit_price.decimal' => 'Price can have at most 2 decimal places.',
            'items.*.discount_amount.decimal' => 'Item discount can have at most 2 decimal places.',
            'items.*.gst_percent.max' => 'GST cannot exceed 100%.',
            'discount_value.decimal' => 'Discount can have at most 2 decimal places.',
            'pay_amount.decimal' => 'Payment can have at most 2 decimal places.',
        ];
    }
}
