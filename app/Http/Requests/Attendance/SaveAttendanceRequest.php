<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Services\AttendanceService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SaveAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attendance.manage');
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'attendance' => ['required', 'array', 'min:1'],
            'attendance.*.status' => ['nullable', Rule::enum(AttendanceStatus::class)],
            'attendance.*.remark' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $date = Carbon::parse($this->input('date'));
            $service = app(AttendanceService::class);

            if (! $service->canEdit($this->user(), $date)) {
                $v->errors()->add('date', 'Only an admin can change attendance older than '.AttendanceService::STAFF_EDIT_WINDOW_DAYS.' days.');

                return;
            }

            // Only people who are on that day's sheet can be marked.
            $allowed = $service->sheet($date)->pluck('id')->all();
            foreach (array_keys($this->input('attendance')) as $id) {
                if (! in_array((int) $id, $allowed, true)) {
                    $v->errors()->add('attendance', 'One of the employees is not on the sheet for this date (inactive, or not yet joined).');

                    return;
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Choose a date.',
            'date.before_or_equal' => 'Attendance cannot be marked for a future date.',
            'attendance.required' => 'There is no attendance to save.',
            'attendance.*.status.enum' => 'Choose Present, Absent, Half Day or Leave.',
        ];
    }
}
