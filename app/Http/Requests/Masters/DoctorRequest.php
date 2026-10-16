<?php

namespace App\Http\Requests\Masters;

use App\Models\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('masters.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'consultation_fee' => $this->input('consultation_fee') === null || $this->input('consultation_fee') === '' ? 0 : $this->input('consultation_fee'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'specialization' => ['nullable', 'string', 'max:150'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique(Doctor::class)->ignore($this->route('doctor'))],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'consultation_fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the doctor\'s name.',
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'consultation_fee.decimal' => 'Fee can have at most 2 decimal places.',
            'consultation_fee.min' => 'Fee cannot be negative.',
            'user_id.unique' => 'This login is already linked to another doctor.',
        ];
    }
}
