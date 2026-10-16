<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\Masters\DoctorRequest;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class DoctorController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            return DataTables::eloquent(Doctor::query()->with('department')->select('doctors.*'))
                ->addColumn('department', fn (Doctor $d) => e($d->department?->name ?? '—'))
                ->editColumn('consultation_fee', fn (Doctor $d) => money($d->consultation_fee))
                ->editColumn('is_active', fn (Doctor $d) => view('partials.active-badge', ['active' => $d->is_active])->render())
                ->addColumn('actions', fn (Doctor $d) => view('partials.master-actions', [
                    'edit' => route('doctors.edit', $d), 'delete' => route('doctors.destroy', $d), 'label' => $d->name,
                ])->render())
                ->rawColumns(['is_active', 'actions'])
                ->toJson();
        }

        return view('doctors.index');
    }

    public function create(): View
    {
        return view('doctors.form', $this->formData(new Doctor(['is_active' => true])));
    }

    public function store(DoctorRequest $request): RedirectResponse
    {
        Doctor::create($request->validated());

        return redirect()->route('doctors.index')->with('success', 'Doctor added.');
    }

    public function edit(Doctor $doctor): View
    {
        return view('doctors.form', $this->formData($doctor));
    }

    public function update(DoctorRequest $request, Doctor $doctor): RedirectResponse
    {
        $doctor->update($request->validated());

        return redirect()->route('doctors.index')->with('success', 'Doctor updated.');
    }

    public function destroy(Doctor $doctor): RedirectResponse
    {
        $doctor->delete();

        return redirect()->route('doctors.index')->with('success', 'Doctor deleted.');
    }

    /** @return array<string, mixed> */
    private function formData(Doctor $doctor): array
    {
        return [
            'doctor' => $doctor,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name']),
            'doctorUsers' => User::role(Role::Doctor->value)->orderBy('name')->get(['id', 'name', 'email']),
        ];
    }
}
