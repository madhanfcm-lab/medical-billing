<?php

namespace App\Http\Requests\Salary;

use App\Enums\PaymentMode;
use App\Models\Employee;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SalaryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('salary.manage');
    }

    /** The form posts <input type="month"> as "2026-10"; it is stored as 2026-10-01. */
    protected function prepareForValidation(): void
    {
        $month = (string) $this->input('for_month');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->merge(['for_month' => $month.'-01']);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', branch_exists('employees')->whereNull('deleted_at')],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'for_month' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today()->endOfMonth()->toDateString()],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'mode' => ['required', Rule::enum(PaymentMode::class)],
            'reference_no' => [Rule::requiredIf(fn () => PaymentMode::tryFrom((string) $this->input('mode'))?->requiresReference() ?? false), 'nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** A reference number only means something for non-cash modes (same rule as invoice payments). */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        if (! PaymentMode::from($data['mode'])->requiresReference()) {
            $data['reference_no'] = null;
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $employee = Employee::withTrashed()->find($this->input('employee_id'));
            if (Carbon::parse($this->input('for_month'))->endOfMonth()->lt($employee->joining_date)) {
                $v->errors()->add('for_month', "{$employee->name} joined on ".format_date($employee->joining_date).', so salary cannot be for an earlier month.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Select an employee.',
            'payment_date.required' => 'Enter the payment date.',
            'payment_date.before_or_equal' => 'Payment date cannot be in the future.',
            'for_month.required' => 'Choose the month this salary is for.',
            'for_month.date_format' => 'Choose a valid month.',
            'for_month.before_or_equal' => 'The salary month cannot be after the current month.',
            'amount.required' => 'Enter the amount.',
            'amount.min' => 'Amount must be at least ₹0.01.',
            'amount.decimal' => 'Amount can have at most 2 decimal places.',
            'mode.required' => 'Select a payment mode.',
            'reference_no.required' => 'Reference number is required for non-cash payments.',
        ];
    }
}
