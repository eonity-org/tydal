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
            // Draft is creation-only: the draft reapers hard-delete every draft
            // past its TTL, so an existing resource must never go back to it (#26).
            'state' => ['required', Rule::enum(ResourceState::class)->except([ResourceState::DRAFT])],
        ]);
    }

    public function state(): ResourceState
    {
        return ResourceState::from($this->validated('state'));
    }
}
