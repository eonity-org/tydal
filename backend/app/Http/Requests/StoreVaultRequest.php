<?php

namespace App\Http\Requests;

use App\Enums\VaultCapability;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by controller policy check
    }

    /**
     * The vault's tenant is the organization the caller is working in (the one
     * selected in the header). Default it from the org context so the UI never
     * has to pick — an explicit organization_id (e.g. a superadmin scripting
     * against the API) is still honored.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('organization_id') && currentOrganizationId()) {
            $this->merge(['organization_id' => currentOrganizationId()]);
        }
    }

    public function rules(): array
    {
        return [
            'organization_id' => 'required|uuid|exists:organizations,id',
            'name' => 'required|string|max:255',
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                // Slug is unique per organization (VAULT_SYSTEM.md §2)
                Rule::unique('vaults', 'slug')->where('organization_id', $this->input('organization_id')),
            ],
            'description' => 'nullable|string',
            'purpose' => ['sometimes', Rule::enum(VaultPurpose::class)],
            'state' => ['sometimes', Rule::enum(VaultState::class)],
            // Auto-generated on create (Vault::creating) — accepted if supplied
            // for API callers, but the UI never sends it.
            'salt' => 'sometimes|string|min:8',
            'has_public_workspace' => 'boolean',
            'is_downloadable' => 'boolean',
            'hash_ttl_hours' => 'nullable|integer|min:1|max:8760', // max 1 year
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'ip',
            'base_url' => 'nullable|url',
            // The capability matrix declares its own shape (VaultCapability),
            // so every knob is typed and unknown keys are rejected rather than
            // silently ignored.
            // The workspaces the vault reads from (the vault form's side of the
            // workspace selector's links); VaultService::syncWorkspaces applies them.
            'workspace_ids' => 'sometimes|array',
            'workspace_ids.*' => 'integer',
            'exposure_policy' => VaultCapability::documentRule(),
            ...VaultCapability::rulesForAll(),
        ];
    }
}
