<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Http\Requests\Employees\EmployeeRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class EmployeeController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            return DataTables::eloquent(Employee::query()->with('user.roles'))
                ->editColumn('employee_code', fn (Employee $e) => '<a href="'.route('employees.show', $e).'" class="fw-semibold">'.e($e->employee_code).'</a>')
                ->editColumn('name', fn (Employee $e) => e($e->name))
                ->editColumn('designation', fn (Employee $e) => e($e->designation ?? '—'))
                ->editColumn('phone', fn (Employee $e) => e($e->phone ?? '—'))
                ->addColumn('login', fn (Employee $e) => $e->user
                    ? '<span class="small">'.e($e->user->email).'</span><div>'.($e->user->is_active ? '' : '<span class="badge bg-secondary">login off</span> ').'<span class="badge bg-light">'.e(Role::tryFrom((string) $e->user->role_name)?->label() ?? '—').'</span></div>'
                    : '<span class="text-muted">No login</span>')
                ->editColumn('joining_date', fn (Employee $e) => format_date($e->joining_date))
                ->editColumn('is_active', fn (Employee $e) => view('partials.active-badge', ['active' => $e->is_active])->render())
                ->addColumn('actions', fn (Employee $e) => view('partials.master-actions', [
                    'edit' => route('employees.edit', $e), 'delete' => route('employees.destroy', $e), 'label' => $e->name,
                ])->render())
                ->rawColumns(['employee_code', 'login', 'is_active', 'actions'])
                ->toJson();
        }

        return view('employees.index', ['unlinkedLogins' => User::query()->withoutStaffRecord()->count()]);
    }

    public function create(): View
    {
        return view('employees.form', [
            'employee' => new Employee(['is_active' => true, 'employee_code' => Employee::suggestCode(), 'joining_date' => today()]),
            'loginUsers' => $this->loginUsers(null),
        ]);
    }

    public function store(EmployeeRequest $request): RedirectResponse
    {
        $employee = Employee::create($request->validated());

        return redirect()->route('employees.show', $employee)->with('success', 'Employee added.');
    }

    public function show(Request $request, Employee $employee, AttendanceService $attendance): View
    {
        $month = $this->month($request);
        $grid = $attendance->monthly($month);
        $mine = collect($grid['rows'])->first(fn ($r) => $r['employee']->id === $employee->id);

        return view('employees.show', [
            'employee' => $employee,
            'month' => $month,
            'days' => $grid['days'],
            'cells' => $mine['cells'] ?? [],
            'counts' => $mine['counts'] ?? array_fill_keys(array_column(AttendanceStatus::cases(), 'value'), 0),
            'payments' => $employee->salaryPayments()->latest('for_month')->latest('id')->get(),
        ]);
    }

    public function edit(Employee $employee): View
    {
        return view('employees.form', ['employee' => $employee, 'loginUsers' => $this->loginUsers($employee->user_id)]);
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->validated());

        return redirect()->route('employees.show', $employee)->with('success', 'Employee updated.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();   // soft delete: attendance and salary history keep pointing at them

        return redirect()->route('employees.index')->with('success', 'Employee removed. Their history is kept.');
    }

    /** One click: put every login that is not on the staff list yet on it. */
    public function syncLogins(EmployeeService $employees): RedirectResponse
    {
        $n = $employees->addAllLogins();

        return redirect()->route('employees.index')->with($n ? 'success' : 'info', $n
            ? "{$n} ".($n === 1 ? 'login was' : 'logins were').' added to Employees. Check each one\'s role and joining date.'
            : 'Every login is already on the staff list.');
    }

    /** Logins that can still be linked to an employee (plus the one already linked to this employee). */
    private function loginUsers(?int $currentUserId)
    {
        return User::query()->where(fn ($q) => $q->withoutStaffRecord()->when($currentUserId, fn ($w) => $w->orWhere('id', $currentUserId)))
            ->orderBy('name')->get(['id', 'name', 'email']);
    }

    private function month(Request $request): Carbon
    {
        $m = (string) $request->input('month');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? Carbon::createFromFormat('Y-m-d', $m.'-01')->startOfDay() : today()->startOfMonth();
    }
}
