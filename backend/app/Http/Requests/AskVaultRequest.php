<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskVaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Organization membership checked in the controller
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:2000'],
            'k' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
