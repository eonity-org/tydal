<?php

namespace App\Http\Requests;

use App\Models\CollectionScheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $collectionId = $this->route('id');

        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'language' => 'sometimes|required|string|max:10',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('collections', 'slug')->ignore($collectionId),
            ],
            'is_active' => 'sometimes|boolean',
            'scheme_id' => 'sometimes|required|uuid|exists:collection_schemes,id',
            'index_id' => 'sometimes|nullable|uuid|exists:search_indexes,id',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('is_active')) {
            $data['is_active'] = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        // If scheme_id is explicitly sent as null/empty, default to the multimedia system scheme
        if ($this->has('scheme_id') && ! $this->filled('scheme_id')) {
            $default = CollectionScheme::where('name', 'multimedia')->where('is_system', true)->value('id');
            if ($default) {
                $data['scheme_id'] = $default;
            }
        }

        if ($data) {
            $this->merge($data);
        }
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The collection name is required.',
            'name.max' => 'The collection name must not exceed 255 characters.',
            'description.max' => 'The description must not exceed 1000 characters.',
            'scheme_id.uuid' => 'The scheme ID must be a valid UUID.',
            'scheme_id.exists' => 'The selected scheme does not exist.',
            'index_id.uuid' => 'The index ID must be a valid UUID.',
            'index_id.exists' => 'The selected search index does not exist.',
            'slug.unique' => 'This slug is already in use.',
        ];
    }
}
