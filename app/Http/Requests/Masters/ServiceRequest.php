<?php

namespace App\Http\Requests\Masters;

use App\Enums\ServiceCategory;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('masters.manage');
    }

    protected function prepareForValidation(): void
    {
        $gst = $this->input('gst_percent');
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'gst_percent' => $gst === null || $gst === '' ? 0 : $gst,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique(Service::class)->where('category', $this->input('category'))->ignore($this->route('service')),
            ],
            'category' => ['required', Rule::enum(ServiceCategory::class)],
            'default_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'gst_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the service / treatment name.',
            'name.unique' => 'This service already exists in the selected category.',
            'category.required' => 'Please choose a category.',
            'default_price.required' => 'Please enter the default price.',
            'default_price.decimal' => 'Price can have at most 2 decimal places.',
            'gst_percent.max' => 'GST cannot be more than 100%.',
        ];
    }
}
