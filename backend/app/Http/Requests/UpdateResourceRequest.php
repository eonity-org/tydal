<?php

namespace App\Http\Requests;

use App\Enums\ResourceState;
use App\Enums\ResourceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResourceRequest extends FormRequest
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
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'required', 'in:'.implode(',', ResourceType::values())],
            'metadata' => ['nullable', 'array'],
            'language' => ['nullable', 'string', 'max:10'],
            'payload' => ['nullable', 'array'],
            'state' => ['sometimes', 'required', Rule::enum(ResourceState::class)],
            'published_at' => ['nullable', 'date'],
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
            'collection_id.exists' => 'The selected collection does not exist.',
            'name.required' => 'The resource name is required.',
            'type.in' => 'The resource type is invalid.',
            'state.Illuminate\\Validation\\Rules\\Enum' => 'The state must be one of: draft, live, archived.',
        ];
    }
}
