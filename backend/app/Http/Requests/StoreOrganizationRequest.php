<?php

namespace App\Http\Requests;

use App\Enums\OrganizationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;  // Authorization handled in policies
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:organizations,slug'],
            // Was a hardcoded 'personal,corporate,public' — three values the
            // OrganizationType enum has never contained, so the endpoint
            // rejected exactly what the admin UI sends.
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'logo' => ['nullable', 'string', 'max:500'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The organization name is required.',
            'name.max' => 'The organization name must not exceed 255 characters.',
            'slug.unique' => 'This slug is already in use.',
            'type.Illuminate\\Validation\\Rules\\Enum' => 'The type must be one of: '.implode(', ', OrganizationType::values()).'.',
        ];
    }
}
