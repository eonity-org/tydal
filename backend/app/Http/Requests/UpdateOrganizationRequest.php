<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
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
        $organizationId = $this->route('organization');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('organizations', 'slug')->ignore($organizationId)],
            'type' => ['sometimes', 'required', 'in:personal,corporate,public'],
            'logo' => ['nullable', 'string', 'max:500'],
            'settings' => ['nullable', 'array'],
            'settings.aity' => ['nullable', 'array'],
            'settings.aity.rag_strict_mode' => ['sometimes', 'boolean'],
            'settings.aity.rag_min_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
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
            'slug.unique' => 'This slug is already in use.',
            'type.in' => 'The type must be one of: personal, corporate, public.',
        ];
    }
}
