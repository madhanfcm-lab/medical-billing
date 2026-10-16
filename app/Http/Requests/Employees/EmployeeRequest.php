<?php

namespace App\Http\Requests\Employees;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('employees.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'user_id' => $this->filled('user_id') ? $this->input('user_id') : null,
            'employee_code' => strtoupper(trim((string) $this->input('employee_code'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'employee_code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9\-_\/]*$/', Rule::unique(Employee::class)->ignore($this->route('employee'))],
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'joining_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:2000-01-01'],
            'is_active' => ['boolean'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at'), Rule::unique(Employee::class, 'user_id')->ignore($this->route('employee'))],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_code.required' => 'Enter an employee code.',
            'employee_code.unique' => 'This employee code is already in use.',
            'employee_code.regex' => 'Use letters, numbers, - _ or / only (e.g. EMP-007).',
            'name.required' => 'Enter the employee\'s name.',
            'user_id.unique' => 'That login is already linked to another employee.',
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'joining_date.required' => 'Enter the joining date.',
            'joining_date.before_or_equal' => 'Joining date cannot be in the future.',
        ];
    }
}
