<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.manage');
    }

    protected function prepareForValidation(): void
    {
        foreach (['invoice_prefix', 'receipt_prefix', 'patient_prefix', 'hospital_gstin'] as $key) {
            if ($this->filled($key)) {
                $this->merge([$key => strtoupper(trim($this->input($key)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'hospital_name' => ['required', 'string', 'max:150'],
            'hospital_address' => ['nullable', 'string', 'max:500'],
            'hospital_phone' => ['nullable', 'string', 'max:20'],
            'hospital_email' => ['nullable', 'email', 'max:150'],
            'hospital_gstin' => ['nullable', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'invoice_prefix' => ['required', 'alpha_dash', 'max:10'],
            'receipt_prefix' => ['required', 'alpha_dash', 'max:10', 'different:invoice_prefix'],
            'patient_prefix' => ['required', 'alpha_dash', 'max:10'],
            'invoice_footer' => ['nullable', 'string', 'max:500'],
            'hospital_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
            'login_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
            'remove_login_image' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'hospital_name.required' => 'Please enter the hospital / clinic name.',
            'hospital_gstin.regex' => 'Enter a valid 15-character GSTIN (e.g. 29ABCDE1234F1Z5).',
            'invoice_prefix.required' => 'Invoice prefix is required.',
            'receipt_prefix.required' => 'Receipt prefix is required.',
            'receipt_prefix.different' => 'Receipt prefix must differ from the invoice prefix.',
            'patient_prefix.required' => 'Patient ID prefix is required.',
            'hospital_logo.image' => 'The logo must be an image file.',
            'hospital_logo.max' => 'The logo may not be larger than 1 MB.',
            'login_image.image' => 'The login picture must be an image file.',
            'login_image.max' => 'The login picture may not be larger than 3 MB.',
        ];
    }
}
