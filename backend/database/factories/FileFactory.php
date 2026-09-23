<?php

namespace Database\Factories;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Models\File;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = $this->faker->randomElement(['pdf', 'jpg', 'png', 'mp4', 'mp3', 'docx']);
        $mimeType = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        };

        return [
            'resource_id' => \App\Models\Resource::factory(),
            'filename' => $this->faker->uuid().'.'.$extension,
            'mime_type' => $mimeType,
            'size' => $this->faker->numberBetween(1024, 104857600), // 1KB to 100MB
            'role' => FileRole::SUPPORTING,
            'relation' => null,
            'usage' => null,
            'path' => $this->faker->filePath(),
            'disk' => 's3',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the file is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a specific resource for the file.
     */
    public function forResource(string $resourceId): static
    {
        return $this->state(fn (array $attributes) => [
            'resource_id' => $resourceId,
        ]);
    }

    /**
     * Mark as the canonical file (source of truth).
     * Canonical files cannot have a relation per spec.
     */
    public function canonical(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => FileRole::CANONICAL,
            'relation' => null,
        ]);
    }

    /**
     * Mark as a component file (part of a composed asset).
     */
    public function component(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => FileRole::COMPONENT,
        ]);
    }

    /**
     * Mark as the snapshot (default preview) for the resource.
     */
    public function withSnapshot(): static
    {
        return $this->state(fn (array $attributes) => [
            'usage' => ['snapshot'],
        ]);
    }

    /**
     * Mark as a translation of the canonical file.
     */
    public function asTranslation(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => FileRole::SUPPORTING,
            'relation' => FileRelation::TRANSLATION,
        ]);
    }

    /**
     * Mark as a rendition (thumbnail, preview image).
     */
    public function asRendition(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => FileRole::SUPPORTING,
            'relation' => FileRelation::RENDITION,
        ]);
    }

    /**
     * Create an image file (canonical).
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'filename' => $this->faker->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'role' => FileRole::CANONICAL,
            'relation' => null,
        ]);
    }

    /**
     * Create a snapshot image file (supporting rendition with snapshot usage).
     */
    public function snapshot(): static
    {
        return $this->state(fn (array $attributes) => [
            'filename' => $this->faker->uuid().'_snapshot.jpg',
            'mime_type' => 'image/jpeg',
            'role' => FileRole::SUPPORTING,
            'relation' => FileRelation::RENDITION,
            'usage' => ['snapshot'],
            'size' => $this->faker->numberBetween(1024, 1048576), // 1KB to 1MB
        ]);
    }
}
