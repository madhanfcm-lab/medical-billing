<?php

namespace App\Http\Requests;

use App\Enums\PaymentMode;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reports.view');
    }

    /** The branch picked on the report page overrides the top-bar branch for this request only. */
    protected function prepareForValidation(): void
    {
        $choice = $this->query('branch');
        if ($choice === null || $choice === '') {
            return;
        }

        $branches = $this->user()->accessibleBranches();
        $current = app(CurrentBranch::class);
        $home = $current->model() ?? $branches->sortBy('id')->first();

        if ($choice === 'all' && $branches->count() > 1) {
            $current->set(null, $home);
        } elseif ($branch = $branches->firstWhere('id', (int) $choice)) {
            $current->set($branch, $home);
        } else {
            abort(403, 'You do not have access to that branch.');
        }
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
            'employee_id' => ['nullable', 'integer', branch_exists('employees')],
            'status' => ['nullable', 'string', 'max:30'],
            'mode' => ['nullable', Rule::enum(PaymentMode::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'from.date_format' => 'Enter the "from" date as a valid date.',
            'to.date_format' => 'Enter the "to" date as a valid date.',
            'to.after_or_equal' => 'The "to" date cannot be before the "from" date.',
        ];
    }
}
