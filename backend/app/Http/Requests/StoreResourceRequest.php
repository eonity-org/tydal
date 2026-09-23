<?php

namespace App\Http\Requests;

use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    /**
     * Nothing used to check this — the request validated and the controller
     * wrote, so a viewer could create resources. Authorizing here rather than
     * in the controller means an unauthorized caller gets 403 instead of a
     * validation report telling them which fields the endpoint wants.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Resource::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'collection_id' => ['required', 'integer', 'exists:collections,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:'.implode(',', ResourceType::values())],
            'metadata' => ['nullable', 'array'],
            'language' => ['nullable', 'string', 'max:10'],
            'payload' => ['nullable', 'array'],
            'payload.downloadable' => ['boolean'],
            'payload.public' => ['boolean'],
            'payload.featured' => ['boolean'],
            'state' => ['required', Rule::enum(ResourceState::class)],
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
            'collection_id.required' => 'The collection is required.',
            'collection_id.integer' => 'The collection ID must be an integer.',
            'collection_id.exists' => 'The selected collection does not exist.',
            'name.required' => 'The resource name is required.',
            'type.in' => 'The resource type is invalid.',
            'state.Illuminate\\Validation\\Rules\\Enum' => 'The state must be one of: draft, live, archived.',
        ];
    }
}
