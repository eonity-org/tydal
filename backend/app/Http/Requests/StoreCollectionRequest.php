<?php

namespace App\Http\Requests;

use App\Models\CollectionScheme;
use Illuminate\Foundation\Http\FormRequest;

class StoreCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'language' => 'required|string|max:10',
            'slug' => 'nullable|string|max:255|unique:collections,slug',
            'is_active' => 'boolean',
            'scheme_id' => 'required|uuid|exists:collection_schemes,id',
            'index_id' => 'nullable|uuid|exists:search_indexes,id',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [
            'is_active' => $this->has('is_active')
                ? filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN)
                : true,
            'language' => $this->filled('language') ? $this->input('language') : 'en',
        ];

        // Default to the multimedia system scheme if none provided
        if (! $this->filled('scheme_id')) {
            $default = CollectionScheme::where('name', 'multimedia')->where('is_system', true)->value('id');
            if ($default) {
                $data['scheme_id'] = $default;
            }
        }

        $this->merge($data);
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
