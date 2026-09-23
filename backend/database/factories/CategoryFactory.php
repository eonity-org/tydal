<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'collection_id' => Collection::factory(),
            'user_owner_id' => User::factory(),
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->randomNumber(4),
            'description' => $this->faker->optional()->text(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the category is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a parent category.
     */
    public function withParent(string $parentId): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parentId,
        ]);
    }

    /**
     * Set a specific collection for the category.
     */
    public function forCollection(string $collectionId): static
    {
        return $this->state(fn (array $attributes) => [
            'collection_id' => $collectionId,
        ]);
    }
}
