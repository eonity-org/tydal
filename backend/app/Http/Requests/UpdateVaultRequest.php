<?php

namespace App\Http\Requests;

use App\Enums\VaultCapability;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Vault;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vaultId = $this->route('id');

        // organization_id is immutable — the tenancy boundary is set at creation.
        return [
            'name' => 'sometimes|required|string|max:255',
            'slug' => [
                'sometimes', 'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('vaults', 'slug')
                    ->ignore($vaultId)
                    ->where('organization_id', Vault::whereKey($vaultId)->value('organization_id')),
            ],
            'description' => 'nullable|string',
            'purpose' => ['sometimes', Rule::enum(VaultPurpose::class)],
            'state' => ['sometimes', Rule::enum(VaultState::class)],
            'salt' => 'sometimes|required|string|min:8',
            'has_public_workspace' => 'boolean',
            'is_downloadable' => 'boolean',
            'hash_ttl_hours' => 'nullable|integer|min:1|max:8760',
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'ip',
            'base_url' => 'nullable|url',
            // The capability matrix declares its own shape (VaultCapability),
            // so every knob is typed and unknown keys are rejected rather than
            // silently ignored.
            'exposure_policy' => VaultCapability::documentRule(),
            ...VaultCapability::rulesForAll(),
        ];
    }
}
