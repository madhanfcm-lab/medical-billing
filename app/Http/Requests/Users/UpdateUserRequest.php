<?php

namespace App\Http\Requests\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'lowercase', Rule::unique(User::class)->ignore($this->route('user'))],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'branches' => ['required_unless:role,admin', 'array'],
            'branches.*' => ['integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'is_active' => ['boolean'],
            'add_as_employee' => ['boolean'],
            'designation' => ['nullable', 'string', 'max:100'],
            'joining_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active'), 'add_as_employee' => $this->boolean('add_as_employee')]);
    }

    public function messages(): array
    {
        return (new StoreUserRequest)->messages();
    }
}
