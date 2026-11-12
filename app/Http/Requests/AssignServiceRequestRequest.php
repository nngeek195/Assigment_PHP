<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_to' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', UserRole::OPERATIONS->value),
            ],
        ];
    }
}
