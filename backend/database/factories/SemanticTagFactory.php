<?php

namespace Database\Factories;

use App\Enums\TagReviewer;
use App\Enums\TagVocabulary;
use App\Models\Organization;
use App\Models\SemanticTag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SemanticTag>
 */
class SemanticTagFactory extends Factory
{
    protected $model = SemanticTag::class;

    private const ENTITY_TYPES = ['person', 'organization', 'place', 'thing', 'tag'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = $this->faker->unique()->word();

        return [
            'organization_id' => Organization::factory(),
            'label' => ucfirst($label),
            'slug' => Str::slug($label).'-'.$this->faker->unique()->randomNumber(4),
            'description' => $this->faker->optional()->text(),
            'entity_type' => $this->faker->randomElement(self::ENTITY_TYPES),
            'vocabulary' => TagVocabulary::ORGANIZATION->value,
            'reviewer' => TagReviewer::USER->value,
        ];
    }

    /**
     * Set a specific organization for the semantic tag.
     */
    public function forOrganization(string $organizationId): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * Create a predefined tag with specific attributes.
     *
     * @param  string  $label  Tag label
     * @param  string|null  $entityType  One of: person, organization, place, thing, tag
     */
    public function withAttributes(string $label, ?string $entityType = null): static
    {
        return $this->state(fn (array $attributes) => [
            'label' => ucfirst($label),
            'slug' => Str::slug($label).'-'.$this->faker->unique()->randomNumber(4),
            'entity_type' => in_array($entityType, self::ENTITY_TYPES) ? $entityType : 'thing',
        ]);
    }
}
