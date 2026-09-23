<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every bulk action: the list of resources to act on.
 *
 * The 200 cap matches the one already applied to the bulk AITY status read
 * (ResourceController::aityStatusBulk) — the dashboard basket is capped at the
 * same number client-side, so a request over the limit is a bug or an abuse,
 * not a user hitting a soft edge, and it fails loudly rather than truncating.
 *
 * Subclasses add the payload for their action; they must merge, not replace,
 * these rules.
 */
class BulkResourceIdsRequest extends FormRequest
{
    public const MAX_IDS = 200;

    public function authorize(): bool
    {
        return true;  // Authorization is per-resource, in the controller
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resource_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'resource_ids.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resource_ids.required' => 'No resources were given.',
            'resource_ids.max' => 'A bulk action is limited to '.self::MAX_IDS.' resources at a time.',
        ];
    }

    /**
     * The requested ids, de-duplicated and re-indexed.
     *
     * @return array<int, string>
     */
    public function resourceIds(): array
    {
        return array_values(array_unique($this->validated('resource_ids')));
    }
}
