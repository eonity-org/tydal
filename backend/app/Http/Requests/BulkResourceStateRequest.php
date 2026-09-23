<?php

namespace App\Http\Requests;

use App\Enums\ResourceState;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class BulkResourceStateRequest extends BulkResourceIdsRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'state' => ['required', Rule::enum(ResourceState::class)],
        ]);
    }

    public function state(): ResourceState
    {
        return ResourceState::from($this->validated('state'));
    }
}
