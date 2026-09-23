<?php

namespace Database\Factories;

use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Resource>
 */
class ResourceFactory extends Factory
{
    protected $model = Resource::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->words(3, true);
        $types = array_column(ResourceType::cases(), 'value');

        return [
            'organization_id' => Organization::factory(),
            'collection_id' => Collection::factory(),
            'user_owner_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->randomNumber(4),
            'description' => $this->faker->optional()->text(),
            'type' => $this->faker->randomElement($types),
            'state' => ResourceState::LIVE,
            'payload' => [
                'downloadable' => $this->faker->boolean(80),
                'public' => $this->faker->boolean(20),
                'featured' => $this->faker->boolean(10),
            ],
            'metadata' => [
                'author' => $this->faker->optional()->name(),
                'language' => $this->faker->languageCode(),
                'keywords' => $this->faker->words(5, false),
            ],
        ];
    }

    /**
     * Mid-creation: never listed, never addressable, prunable.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['state' => ResourceState::DRAFT]);
    }

    /**
     * Withdrawn: out of every listing and no longer addressable.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['state' => ResourceState::ARCHIVED]);
    }

    /**
     * Set a specific type for the resource.
     */
    public function type(ResourceType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type->value,
        ]);
    }

    /**
     * Set a specific collection for the resource.
     */
    public function forCollection(string $collectionId): static
    {
        return $this->state(fn (array $attributes) => [
            'collection_id' => $collectionId,
        ]);
    }
}
