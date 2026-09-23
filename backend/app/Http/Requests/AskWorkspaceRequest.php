<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in the controller via policy
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:2000'],
            'k' => ['sometimes', 'integer', 'min:1', 'max:20'],
            // When true: metadata chunks (name/description/tags) are excluded from
            // context — only raw document text is used. Overrides the org-level
            // rag_strict_mode for this single request.
            'strict' => ['sometimes', 'boolean'],
        ];
    }
}
