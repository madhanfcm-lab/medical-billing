<?php

namespace App\Http\Requests\Branches;

use App\Models\Branch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('branches.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code'))), 'is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'alpha_dash', 'max:12', Rule::unique('branches', 'code')->ignore($this->route('branch'))],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['boolean'],
        ];
    }

    /** There must always be one branch people can work in. */
    public function after(): array
    {
        return [function (Validator $v) {
            $branch = $this->route('branch');
            if ($branch && ! $this->boolean('is_active') && $branch->is_active && Branch::active()->whereKeyNot($branch->id)->doesntExist()) {
                $v->errors()->add('is_active', 'This is the only active branch, so it cannot be switched off.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the branch name.',
            'code.required' => 'Please enter a short code, e.g. VEL.',
            'code.unique' => 'Another branch already uses this code.',
        ];
    }
}
