<?php

namespace App\Http\Requests\Patients;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatientRequest extends FormRequest
{
    private const MOBILE = '/^[6-9]\d{9}$/';

    public function authorize(): bool
    {
        return $this->user()->can('patients.manage');
    }

    protected function prepareForValidation(): void
    {
        // Tolerate "+91 98765 43210" / "098765-43210" pasted by reception.
        foreach (['phone', 'alternate_phone', 'emergency_contact_phone'] as $key) {
            if ($this->filled($key)) {
                $digits = preg_replace('/\D+/', '', $this->input($key));
                if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                    $digits = substr($digits, 2);
                } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
                    $digits = substr($digits, 1);
                }
                $this->merge([$key => $digits]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'phone' => ['required', 'regex:'.self::MOBILE, Rule::unique(Patient::class)->ignore($this->route('patient'))],
            'alternate_phone' => ['nullable', 'regex:'.self::MOBILE],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'blood_group' => ['nullable', Rule::enum(BloodGroup::class)],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'regex:'.self::MOBILE],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the patient\'s name.',
            'gender.required' => 'Please select a gender.',
            'date_of_birth.before_or_equal' => 'Date of birth cannot be in the future.',
            'date_of_birth.after' => 'Please enter a valid date of birth.',
            'phone.required' => 'Mobile number is required.',
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number (starts with 6-9).',
            'phone.unique' => 'A patient with this mobile number is already registered.',
            'alternate_phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'emergency_contact_phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'email.email' => 'Enter a valid e-mail address.',
        ];
    }
}
