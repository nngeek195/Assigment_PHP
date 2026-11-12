<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in(['approved', 'rejected', 'returned']),
            ],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
