<?php

namespace App\Http\Controllers;

use App\Http\Requests\Masters\DepartmentRequest;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class DepartmentController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            return DataTables::eloquent(Department::query()->withCount('doctors'))
                ->editColumn('is_active', fn (Department $d) => view('partials.active-badge', ['active' => $d->is_active])->render())
                ->addColumn('actions', fn (Department $d) => view('partials.master-actions', [
                    'edit' => route('departments.edit', $d), 'delete' => route('departments.destroy', $d),
                    'label' => $d->name,
                ])->render())
                ->rawColumns(['is_active', 'actions'])
                ->toJson();
        }

        return view('departments.index');
    }

    public function create(): View
    {
        return view('departments.form', ['department' => new Department(['is_active' => true])]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        Department::create($request->validated());

        return redirect()->route('departments.index')->with('success', 'Department added.');
    }

    public function edit(Department $department): View
    {
        return view('departments.form', compact('department'));
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

        return redirect()->route('departments.index')->with('success', 'Department updated.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        $department->delete();

        return redirect()->route('departments.index')->with('success', 'Department deleted.');
    }
}
