<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMode;
use App\Exports\ReportExport;
use App\Http\Requests\ReportRequest;
use App\Models\Doctor;
use App\Models\Employee;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Support\CurrentBranch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /** Rows shown per table on screen; exports always contain everything. */
    public const SCREEN_ROW_LIMIT = 500;

    public function index(): View
    {
        return view('reports.index', ['reports' => ReportRegistry::all()]);
    }

    public function show(ReportRequest $request, string $report): View
    {
        $def = $this->find($report);
        $filters = ReportFilters::for($def, $request->validated());

        return view('reports.show', [
            'def' => $def,
            'filters' => $filters,
            'result' => $def->run($filters, self::SCREEN_ROW_LIMIT),
            'query' => $request->query() + ['branch' => $this->branchValue()],
            'branchChoice' => $this->branchValue(),
            'doctors' => in_array('doctor', $def->filters(), true) ? Doctor::withTrashed()->orderBy('name')->get(['id', 'name']) : collect(),
            'employees' => in_array('employee', $def->filters(), true) ? Employee::withTrashed()->orderBy('name')->get(['id', 'name', 'employee_code']) : collect(),
            'modes' => PaymentMode::options(),
            'limit' => self::SCREEN_ROW_LIMIT,
        ]);
    }

    public function excel(ReportRequest $request, string $report): BinaryFileResponse
    {
        $def = $this->find($report);
        $result = $def->run(ReportFilters::for($def, $request->validated()));

        return Excel::download(new ReportExport($result), $this->filename($def).'.xlsx');
    }

    public function pdf(ReportRequest $request, string $report): Response
    {
        $def = $this->find($report);
        $result = $def->run(ReportFilters::for($def, $request->validated()));

        return Pdf::loadView('reports.pdf', ['result' => $result, 'printedBy' => $request->user()->name])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($def).'.pdf');
    }

    /** What the branch picker shows: "all" or a branch id. */
    private function branchValue(): string
    {
        $current = app(CurrentBranch::class);

        return $current->isAll() ? 'all' : (string) $current->filterId();
    }

    private function find(string $key): Report
    {
        return ReportRegistry::find($key) ?? abort(404);
    }

    private function filename(Report $def): string
    {
        return Str::slug(setting('hospital_name')).'-'.$def->key().'-'.today()->format('d-m-Y');
    }
}
