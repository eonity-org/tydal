<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'user_owner_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->randomNumber(4),
            'description' => $this->faker->optional()->text(),
            'scheme_id' => CollectionScheme::inRandomOrder()->first()?->id,
            'index_id' => SearchIndex::active()->inRandomOrder()->first()?->id,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the collection is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Assign a specific scheme.
     */
    public function withScheme(CollectionScheme $scheme): static
    {
        return $this->state(fn (array $attributes) => [
            'scheme_id' => $scheme->id,
        ]);
    }
}
