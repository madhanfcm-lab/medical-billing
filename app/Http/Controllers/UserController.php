<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\EmployeeService;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $query = User::query()->with(['roles', 'branches:branches.id,name', 'employee:id,user_id,employee_code'])->select('users.*');

            return DataTables::eloquent($query)
                ->addColumn('role', fn (User $u) => e(Role::tryFrom((string) $u->role_name)?->label() ?? '—'))
                ->addColumn('branches', fn (User $u) => $u->hasRole(Role::Admin->value) ? '<span class="text-muted">All branches</span>' : e($u->branches->pluck('name')->implode(', ') ?: '—'))
                ->addColumn('employee', fn (User $u) => $u->employee
                    ? ($u->employee->branch_id === (app(CurrentBranch::class)->filterId() ?? $u->employee->branch_id)
                        ? '<a href="'.route('employees.show', $u->employee).'" class="badge bg-primary text-decoration-none">'.e($u->employee->employee_code).'</a>'
                        : '<span class="badge bg-secondary">'.e($u->employee->employee_code).'</span>')
                    : '<span class="text-muted">Not on staff list</span>')
                ->editColumn('is_active', fn (User $u) => $u->is_active
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-secondary">Inactive</span>')
                ->addColumn('actions', fn (User $u) => view('users._actions', ['user' => $u])->render())
                ->rawColumns(['branches', 'employee', 'is_active', 'actions'])
                ->toJson();
        }

        return view('users.index');
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User(['is_active' => true]), 'branches' => Branch::active()->orderBy('name')->get(), 'assigned' => [app(CurrentBranch::class)->writeId()]]);
    }

    public function store(StoreUserRequest $request, EmployeeService $employees): RedirectResponse
    {
        $data = $request->validated();
        $user = User::create(collect($data)->except(['role', 'branches', 'add_as_employee', 'designation', 'joining_date'])->all());
        $user->assignRole($data['role']);
        $user->branches()->sync($data['branches'] ?? []);

        // Anyone who works here and can log in is usually also on the staff list (attendance, salary).
        $staff = ! empty($data['add_as_employee']) ? $employees->createForUser($user, $data) : null;

        return redirect()->route('users.index')->with('success', "User {$user->name} created".($staff ? " and added to Employees as {$staff->employee_code}." : '.'));
    }

    public function edit(User $user): View
    {
        return view('users.form', ['user' => $user->load('roles'), 'branches' => Branch::active()->orderBy('name')->get(), 'assigned' => $user->branches()->pluck('branches.id')->all()]);
    }

    public function update(UpdateUserRequest $request, User $user, EmployeeService $employees): RedirectResponse
    {
        $data = $request->validated();

        // An admin must not lock themselves out or demote the account they are using.
        if ($user->is($request->user())) {
            $data['is_active'] = true;
            $data['role'] = Role::Admin->value;
        }

        $user->fill(collect($data)->except(['role', 'branches', 'password', 'add_as_employee', 'designation', 'joining_date'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->syncRoles([$data['role']]);
        // An admin's own branches are left alone (they can always use every branch), so the form cannot lock them out.
        if (isset($data['branches'])) {
            $user->branches()->sync($data['branches']);
        }

        $employees->syncFromUser($user);
        if (! empty($data['add_as_employee'])) {
            $employees->createForUser($user, $data);
        }

        return redirect()->route('users.index')->with('success', "User {$user->name} updated.");
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403, 'You cannot deactivate your own account.');

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->name.($user->is_active ? ' activated.' : ' deactivated.'));
    }
}
