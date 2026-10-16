<?php

namespace App\Http\Requests\FollowUps;

use Illuminate\Foundation\Http\FormRequest;

class CancelFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('followUp'));
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:255']];
    }
}
