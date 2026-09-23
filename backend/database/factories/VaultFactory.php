<?php

namespace Database\Factories;

use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Organization;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vault>
 */
class VaultFactory extends Factory
{
    protected $model = Vault::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->randomNumber(4),
            'description' => $this->faker->optional()->sentence(),
            'purpose' => VaultPurpose::DELIVERY,
            'state' => VaultState::PRIVATE,
            'salt' => 'test-salt-'.$this->faker->unique()->randomNumber(6),
            'has_public_workspace' => false,
            'is_downloadable' => false,
            'hash_ttl_hours' => null,
            'allowed_ips' => null,
            'exposure_policy' => null,
            'base_url' => null,
        ];
    }

    public function purpose(VaultPurpose $purpose): static
    {
        return $this->state(fn (array $attributes) => ['purpose' => $purpose]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => ['state' => VaultState::PUBLIC]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['state' => VaultState::DISABLED]);
    }

    public function downloadable(): static
    {
        return $this->state(fn (array $attributes) => ['is_downloadable' => true]);
    }

    public function withPublicWorkspace(): static
    {
        return $this->state(fn (array $attributes) => ['has_public_workspace' => true]);
    }

    public function withTtl(int $hours): static
    {
        return $this->state(fn (array $attributes) => ['hash_ttl_hours' => $hours]);
    }

    public function withAllowedIps(array $ips): static
    {
        return $this->state(fn (array $attributes) => ['allowed_ips' => $ips]);
    }

    public function withBaseUrl(string $url): static
    {
        return $this->state(fn (array $attributes) => ['base_url' => $url]);
    }
}
