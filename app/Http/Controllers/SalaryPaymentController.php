<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMode;
use App\Http\Requests\Salary\SalaryPaymentRequest;
use App\Models\Employee;
use App\Models\SalaryPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

/** Manual salary entries only. No calculation: you type what was paid. */
class SalaryPaymentController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $filtered = fn () => SalaryPayment::query()
                ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
                ->when($request->filled('month') && preg_match('/^\d{4}-\d{2}$/', $request->input('month')), fn ($q) => $q->whereDate('for_month', $request->input('month').'-01'))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('payment_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('payment_date', '<=', $request->input('to')));

            return DataTables::eloquent($filtered()->with('employee:id,name,employee_code,deleted_at')->select('salary_payments.*'))
                ->editColumn('payment_date', fn (SalaryPayment $p) => format_date($p->payment_date))
                ->addColumn('employee', fn (SalaryPayment $p) => '<a href="'.route('employees.show', $p->employee_id).'">'.e($p->employee->name).'</a><div class="small text-muted">'.e($p->employee->employee_code).'</div>')
                ->editColumn('for_month', fn (SalaryPayment $p) => $p->month_label)
                ->editColumn('amount', fn (SalaryPayment $p) => money($p->amount))
                ->editColumn('mode', fn (SalaryPayment $p) => e($p->mode->label()))
                ->editColumn('reference_no', fn (SalaryPayment $p) => e($p->reference_no ?? '—'))
                ->addColumn('actions', fn (SalaryPayment $p) => view('partials.master-actions', [
                    'edit' => route('salary-payments.edit', $p), 'delete' => route('salary-payments.destroy', $p),
                    'label' => 'this '.money($p->amount).' payment',
                ])->render())
                ->filterColumn('employee', fn ($q, $kw) => $q->whereHas('employee', fn ($e) => $e->withTrashed()->where(fn ($w) => $w->where('name', 'like', "%{$kw}%")->orWhere('employee_code', 'like', "%{$kw}%"))))
                ->orderColumn('employee', fn ($q, $dir) => $q->orderBy('employee_id', $dir))
                ->with('total', fn () => money($filtered()->sum('amount')))
                ->rawColumns(['employee', 'actions'])
                ->toJson();
        }

        return view('salary.index', ['employees' => Employee::withTrashed()->orderBy('name')->get(['id', 'name', 'employee_code'])]);
    }

    public function create(Request $request): View
    {
        return view('salary.form', $this->formData(new SalaryPayment([
            'payment_date' => today(), 'for_month' => today()->startOfMonth(), 'mode' => PaymentMode::Cash,
            'employee_id' => $request->integer('employee') ?: null,
        ])));
    }

    public function store(SalaryPaymentRequest $request): RedirectResponse
    {
        $payment = SalaryPayment::create($request->validated());

        return redirect()->route('employees.show', $payment->employee_id)
            ->with('success', 'Salary payment of '.money($payment->amount).' for '.$payment->month_label.' recorded.');
    }

    public function edit(SalaryPayment $salaryPayment): View
    {
        return view('salary.form', $this->formData($salaryPayment));
    }

    public function update(SalaryPaymentRequest $request, SalaryPayment $salaryPayment): RedirectResponse
    {
        $salaryPayment->update($request->validated());

        return redirect()->route('salary-payments.index')->with('success', 'Salary payment updated.');
    }

    public function destroy(SalaryPayment $salaryPayment): RedirectResponse
    {
        $salaryPayment->delete();

        return redirect()->route('salary-payments.index')->with('success', 'Salary payment deleted.');
    }

    /** @return array<string, mixed> */
    private function formData(SalaryPayment $payment): array
    {
        return [
            'payment' => $payment,
            'employees' => Employee::query()->where(fn ($q) => $q->active()->orWhere('id', $payment->employee_id))->orderBy('name')->get(['id', 'name', 'employee_code', 'joining_date']),
            'modes' => collect(PaymentMode::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label(), 'ref' => $m->requiresReference()]),
        ];
    }
}
