<?php

namespace App\Http\Requests\Billing;

use App\Enums\ServiceCategory;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adding a service to the catalogue while billing, without leaving the bill. */
class QuickServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoices.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name')), 'gst_percent' => $this->filled('gst_percent') ? $this->input('gst_percent') : 0]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique(Service::class)->where('category', $this->input('category'))],
            'category' => ['required', Rule::enum(ServiceCategory::class)],
            'default_price' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'gst_percent' => ['numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter the service name.',
            'name.unique' => 'This service already exists in that category. Choose it from the list.',
            'category.required' => 'Choose a category.',
            'default_price.required' => 'Enter the usual price (you can change it on each bill).',
            'default_price.decimal' => 'Price can have at most 2 decimal places.',
        ];
    }
}
