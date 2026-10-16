<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Http\Requests\Attendance\SaveAttendanceRequest;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /** The daily sheet: every active employee with Present / Absent / Half Day / Leave. */
    public function index(Request $request): View
    {
        $date = $this->date($request);
        $existing = Attendance::query()->whereDate('attendance_date', $date->toDateString())->with('updater:id,name')->get()->keyBy('employee_id');

        return view('attendance.index', [
            'date' => $date,
            'employees' => $this->attendance->sheet($date),
            'records' => $existing,
            'editable' => $this->attendance->canEdit($request->user(), $date),
            'oldest' => $request->user()->can('attendance.edit_past') ? null : today()->subDays(AttendanceService::STAFF_EDIT_WINDOW_DAYS),
            'statuses' => AttendanceStatus::cases(),
        ]);
    }

    public function store(SaveAttendanceRequest $request): RedirectResponse
    {
        $date = Carbon::parse($request->validated('date'));
        $r = $this->attendance->save($date, $request->validated('attendance'));

        $saved = $r['created'] + $r['updated'];
        $msg = $saved > 0
            ? 'Attendance for '.format_date($date)." saved ({$saved} ".($saved === 1 ? 'entry' : 'entries').' changed).'
            : 'Nothing to save — attendance is already up to date.';

        return redirect()->route('attendance.index', ['date' => $date->toDateString()])
            ->with('success', $msg.($r['skipped'] ? " {$r['skipped']} not marked yet." : ''));
    }

    /** Employees x days grid for a month. */
    public function monthly(Request $request): View
    {
        $m = (string) $request->input('month');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? Carbon::createFromFormat('Y-m-d', $m.'-01')->startOfDay() : today()->startOfMonth();

        return view('attendance.monthly', $this->attendance->monthly($month) + ['statuses' => AttendanceStatus::cases()]);
    }

    private function date(Request $request): Carbon
    {
        $d = (string) $request->input('date');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))
            ? Carbon::parse($d)->startOfDay() : today();

        return $date->gt(today()) ? today() : $date;
    }
}
