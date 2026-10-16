<?php

namespace App\Http\Requests\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'lowercase', Rule::unique(User::class)],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => ['required', 'confirmed', Password::min(8)],
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
        return [
            'name.required' => 'Please enter the staff member\'s name.',
            'email.unique' => 'A user with this e-mail already exists.',
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'role.required' => 'Please choose a role.',
            'branches.required_unless' => 'Choose at least one branch this person works in.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }
}
