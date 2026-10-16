<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /** Staff may correct attendance this many days back; older dates need attendance.edit_past (admin). */
    public const STAFF_EDIT_WINDOW_DAYS = 3;

    /** Future dates can never be marked; past dates depend on the user's rights. */
    public function canEdit(User $user, Carbon $date): bool
    {
        if ($date->copy()->startOfDay()->gt(today())) {
            return false;
        }

        return $user->can('attendance.edit_past')
            || $date->copy()->startOfDay()->gte(today()->subDays(self::STAFF_EDIT_WINDOW_DAYS));
    }

    /**
     * Who appears on a day's sheet: active employees who had joined by then, plus anyone who
     * already has a record that day (so history stays visible after someone is deactivated).
     *
     * @return Collection<int, Employee>
     */
    public function sheet(Carbon $date): Collection
    {
        $withRecord = Attendance::query()->whereDate('attendance_date', $date->toDateString())->select('employee_id');

        return Employee::query()
            ->where(fn ($q) => $q->active()->joinedBy($date)->orWhereIn('id', $withRecord))
            ->orderBy('name')->get();
    }

    /**
     * Save the whole sheet in one go. Rows with no status are skipped; unchanged rows are not touched
     * (so "updated by" only moves when something really changed).
     *
     * @param  array<int|string, array{status?: ?string, remark?: ?string}>  $rows  keyed by employee id
     * @return array{created: int, updated: int, unchanged: int, skipped: int}
     */
    public function save(Carbon $date, array $rows): array
    {
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        DB::transaction(function () use ($date, $rows, &$result) {
            $existing = Attendance::query()->whereDate('attendance_date', $date->toDateString())->get()->keyBy('employee_id');

            foreach ($rows as $employeeId => $row) {
                $status = $row['status'] ?? null;
                if (blank($status)) {
                    $result['skipped']++;

                    continue;
                }
                $remark = filled($row['remark'] ?? null) ? trim($row['remark']) : null;
                $record = $existing->get((int) $employeeId);

                if (! $record) {
                    try {
                        // savepoint so a concurrent duplicate does not poison the outer transaction
                        DB::transaction(fn () => Attendance::create([
                            'employee_id' => $employeeId, 'attendance_date' => $date->toDateString(), 'status' => $status, 'remark' => $remark,
                        ]));
                        $result['created']++;

                        continue;
                    } catch (UniqueConstraintViolationException) {
                        // somebody saved this employee/day a moment ago: fall through and update theirs
                        $record = Attendance::query()->where('employee_id', $employeeId)->whereDate('attendance_date', $date->toDateString())->firstOrFail();
                    }
                }

                if ($record->status->value === $status && $record->remark === $remark) {
                    $result['unchanged']++;

                    continue;
                }
                $record->update(['status' => $status, 'remark' => $remark]);
                $result['updated']++;
            }
        });

        return $result;
    }

    /**
     * Month grid: employees x days, with P / A / HD / L counts.
     *
     * @return array{month: Carbon, days: int, rows: list<array{employee: Employee, cells: array<int, ?AttendanceStatus>, counts: array<string, int>}>, totals: array<string, int>}
     */
    public function monthly(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $records = Attendance::query()->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('employee_id');

        $employees = Employee::withTrashed()
            ->where(fn ($q) => $q->where(fn ($a) => $a->active()->whereNull('deleted_at')->joinedBy($end))->orWhereIn('id', $records->keys()))
            ->orderBy('name')->get();

        $zero = collect(AttendanceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => 0])->all();
        $totals = $zero;
        $rows = [];
        foreach ($employees as $employee) {
            $cells = [];
            $counts = $zero;   // each employee starts from zero (not from the running totals)
            foreach ($records->get($employee->id, collect()) as $a) {
                $cells[(int) $a->attendance_date->day] = $a->status;
                $counts[$a->status->value]++;
                $totals[$a->status->value]++;
            }
            $rows[] = ['employee' => $employee, 'cells' => $cells, 'counts' => $counts];
        }

        return ['month' => $start, 'days' => $start->daysInMonth, 'rows' => $rows, 'totals' => $totals];
    }
}
