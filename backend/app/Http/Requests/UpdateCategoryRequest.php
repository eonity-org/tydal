<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $categoryId = $this->route('id');

        return [
            'collection_id' => 'sometimes|required|uuid|exists:collections,id',
            'parent_id' => 'nullable|uuid|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')->ignore($categoryId),
            ],
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Prevent self-parenting
            if ($this->has('parent_id') && $this->input('parent_id') === $this->route('id')) {
                $validator->errors()->add('parent_id', 'A category cannot be its own parent.');
            }

            // Ensure parent_id belongs to the same collection
            if ($this->has('parent_id') && $this->has('collection_id') && $this->input('parent_id')) {
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
