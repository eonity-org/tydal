<?php

namespace Database\Factories;

use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VaultLink>
 */
class VaultLinkFactory extends Factory
{
    protected $model = VaultLink::class;

    public function definition(): array
    {
        // Build a placeholder link_key and hash — tests usually override via ->create([...])
        return [
            'vault_id' => Vault::factory(),
            'workspace_id' => Workspace::factory(),
            'resource_id' => Resource::factory(),
            'file_id' => null,
            'link_key' => $this->faker->unique()->sha256(),
            'hash' => $this->faker->unique()->regexify('[A-Za-z0-9]{8}'),
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function expiresIn(int $hours): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addHours($hours),
        ]);
    }
}
