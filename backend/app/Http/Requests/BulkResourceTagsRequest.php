<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bulk semantic tagging.
 *
 * Deliberately additive/subtractive rather than a set replacement: the
 * single-resource endpoint (SemanticTagController::syncResource) takes the
 * whole tag set and `sync()`s it, which is right when a human is editing one
 * resource's tags in a panel and wrong for "add 'Barcelona' to these forty" —
 * that must not wipe the tags each of the forty already carries.
 */
class BulkResourceTagsRequest extends BulkResourceIdsRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'tag_ids' => ['required', 'array', 'min:1'],
            'tag_ids.*' => ['integer'],
            'mode' => ['required', 'in:add,remove'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'mode.in' => 'The mode must be either add or remove.',
        ]);
    }

    /**
     * @return array<int, int>
     */
    public function tagIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('tag_ids'))));
    }

    public function isAdding(): bool
    {
        return $this->validated('mode') === 'add';
    }
}
