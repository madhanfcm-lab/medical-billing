<?php

namespace App\Http\Controllers;

use App\Http\Requests\Branches\BranchRequest;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class BranchController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            return DataTables::eloquent(Branch::query()->withCount('users'))
                ->editColumn('name', fn (Branch $b) => '<a href="'.route('branches.edit', $b).'" class="fw-semibold">'.e($b->name).'</a><div class="small text-muted">'.e($b->code).'</div>')
                ->editColumn('address', fn (Branch $b) => e($b->address ?: '—'))
                ->editColumn('phone', fn (Branch $b) => e($b->phone ?: '—'))
                ->editColumn('is_active', fn (Branch $b) => $b->is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>')
                ->addColumn('actions', fn (Branch $b) => '<a href="'.route('branches.edit', $b).'" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>')
                ->rawColumns(['name', 'is_active', 'actions'])
                ->toJson();
        }

        return view('branches.index');
    }

    public function create(): View
    {
        return view('branches.form', ['branch' => new Branch(['is_active' => true])]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = Branch::create($request->validated());

        return redirect()->route('branches.index')->with('success', "Branch {$branch->name} added. Give your staff access to it from Users.");
    }

    public function edit(Branch $branch): View
    {
        return view('branches.form', ['branch' => $branch]);
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        return redirect()->route('branches.index')->with('success', "Branch {$branch->name} updated.");
    }
}
