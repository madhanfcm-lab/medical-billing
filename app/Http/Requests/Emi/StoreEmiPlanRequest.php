<?php

namespace App\Http\Requests\Emi;

use App\Enums\PaymentMode;
use App\Models\Invoice;
use App\Services\EmiScheduleGenerator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StoreEmiPlanRequest extends FormRequest
{
    use ValidatesEmiSchedule;

    public function authorize(): bool
    {
        return $this->user()->can('emi.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBlanks(['down_payment' => 0]);
    }

    public function rules(): array
    {
        $patient = $this->input('patient_id');

        return $this->scheduleRules(startMustNotBePast: ! $this->user()->hasRole('admin')) + [
            'patient_id' => ['required', 'integer', branch_exists('patients')->whereNull('deleted_at')],
            'visit_id' => ['nullable', 'integer', branch_exists('visits')->where('patient_id', $patient)],
            'invoice_id' => ['nullable', 'integer', branch_exists('invoices')->where('patient_id', $patient)],
            'treatment_name' => ['required', 'string', 'max:200'],
            'total_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'down_payment' => ['numeric', 'min:0', 'decimal:0,2', 'lt:total_amount'],
            'pay_mode' => ['nullable', Rule::enum(PaymentMode::class)],
            'pay_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $financed = from_paise(to_paise($this->input('total_amount')) - to_paise($this->input('down_payment')));
            try {
                app(EmiScheduleGenerator::class)->generate(
                    $financed, $this->input('calc_by'), $this->input('months'), $this->input('monthly_amount'),
                    Carbon::parse($this->input('start_date')), (int) $this->input('due_day'),
                );
            } catch (InvalidArgumentException $e) {
                $v->errors()->add($this->input('calc_by') === 'months' ? 'months' : 'monthly_amount', $e->getMessage());
            }

            if (to_paise($this->input('down_payment')) > 0) {
                $mode = PaymentMode::tryFrom((string) $this->input('pay_mode'));
                if (! $mode) {
                    $v->errors()->add('pay_mode', 'Select a payment mode for the down payment.');
                } elseif ($mode->requiresReference() && blank($this->input('pay_reference'))) {
                    $v->errors()->add('pay_reference', 'Reference number is required for '.$mode->label().' payments.');
                }
            }

            if ($this->filled('invoice_id')) {
                $invoice = Invoice::find($this->input('invoice_id'));
                if ($invoice->isCancelled()) {
                    $v->errors()->add('invoice_id', 'A cancelled invoice cannot be put on an EMI plan.');
                } elseif ($invoice->hasOpenEmiPlan()) {
                    $v->errors()->add('invoice_id', 'This invoice already has an open EMI plan.');
                } elseif (to_paise($this->input('total_amount')) > to_paise($invoice->balance_amount)) {
                    $v->errors()->add('total_amount', 'The plan total cannot exceed the invoice balance of '.money($invoice->balance_amount).'.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return $this->scheduleMessages() + [
            'patient_id.required' => 'Please select a patient.',
            'visit_id.exists' => 'The selected visit does not belong to this patient.',
            'invoice_id.exists' => 'The selected invoice does not belong to this patient.',
            'treatment_name.required' => 'Enter the treatment name.',
            'total_amount.required' => 'Enter the total amount.',
            'total_amount.min' => 'Total amount must be at least ₹0.01.',
            'total_amount.decimal' => 'Total amount can have at most 2 decimal places.',
            'down_payment.lt' => 'Down payment must be less than the total amount.',
            'down_payment.decimal' => 'Down payment can have at most 2 decimal places.',
        ];
    }
}
