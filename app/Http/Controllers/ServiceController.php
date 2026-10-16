<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\QuickServiceRequest;
use App\Http\Requests\Masters\ServiceRequest;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class ServiceController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            return DataTables::eloquent(Service::query())
                ->editColumn('category', fn (Service $s) => $s->category->label())
                ->editColumn('default_price', fn (Service $s) => money($s->default_price))
                ->editColumn('gst_percent', fn (Service $s) => rtrim(rtrim($s->gst_percent, '0'), '.').'%')
                ->editColumn('is_active', fn (Service $s) => view('partials.active-badge', ['active' => $s->is_active])->render())
                ->addColumn('actions', fn (Service $s) => view('partials.master-actions', [
                    'edit' => route('services.edit', $s), 'delete' => route('services.destroy', $s), 'label' => $s->name,
                ])->render())
                ->rawColumns(['is_active', 'actions'])
                ->toJson();
        }

        return view('services.index');
    }

    /** Add a service from inside the bill. Returns the new entry so the bill can select it. */
    public function quickStore(QuickServiceRequest $request): JsonResponse
    {
        $service = Service::create($request->validated() + ['is_active' => true]);

        return response()->json([
            'id' => $service->id, 'name' => $service->name, 'group' => $service->category->label(),
            'price' => (float) $service->default_price, 'gst' => (float) $service->gst_percent,
        ], 201);
    }

    public function create(): View
    {
        return view('services.form', ['service' => new Service(['is_active' => true, 'gst_percent' => 0])]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        Service::create($request->validated());

        return redirect()->route('services.index')->with('success', 'Service added.');
    }

    public function edit(Service $service): View
    {
        return view('services.form', compact('service'));
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->validated());

        return redirect()->route('services.index')->with('success', 'Service updated.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return redirect()->route('services.index')->with('success', 'Service deleted.');
    }
}
