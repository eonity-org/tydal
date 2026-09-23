<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    /** See StoreResourceRequest::authorize() — same omission, same fix. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'collection_id' => 'required|uuid|exists:collections,id',
            'parent_id' => 'nullable|uuid|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'slug' => 'nullable|string|max:255|unique:categories,slug',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->has('is_active') ? filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN) : true,
        ]);
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Ensure parent_id belongs to the same collection
            if ($this->has('parent_id') && $this->input('parent_id')) {
                $parentCollectionId = Category::find($this->input('parent_id'))?->collection_id;

                if ($parentCollectionId && $parentCollectionId !== $this->input('collection_id')) {
                    $validator->errors()->add('parent_id', 'The parent category must belong to the same collection.');
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collection_id.required' => 'The collection ID is required.',
            'collection_id.uuid' => 'The collection ID must be a valid UUID.',
            'collection_id.exists' => 'The selected collection does not exist.',
            'parent_id.uuid' => 'The parent category ID must be a valid UUID.',
            'parent_id.exists' => 'The selected parent category does not exist.',
            'name.required' => 'The category name is required.',
            'name.max' => 'The category name must not exceed 255 characters.',
            'description.max' => 'The description must not exceed 1000 characters.',
            'slug.unique' => 'This slug is already in use.',
        ];
    }
}
