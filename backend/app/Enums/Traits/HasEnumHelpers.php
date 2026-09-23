<?php

namespace App\Enums\Traits;

/**
 * Enum Helper Trait
 *
 * Provides common helper methods for PHP 8.1 enums.
 * Use this trait to add consistent functionality across all backed enums.
 */
trait HasEnumHelpers
{
    /**
     * Get all enum values as array
     *
     * Useful for:
     * - Database migrations: $table->enum('type', ResourceType::values())
     * - Validation: 'in:' . implode(',', ResourceType::values())
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all enum names as array
     *
     * Returns the case names (not backing values).
     *
     * @return array<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Get all cases as associative array [name => value]
     *
     * Useful for select dropdowns.
     *
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        return array_combine(self::names(), self::values());
    }

    /**
     * Get all cases with labels as array [value => label]
     *
     * Override label() method in enum to customize labels.
     *
     * @return array<string, string>
     */
    public static function toLabelArray(): array
    {
        $labels = [];
        foreach (self::cases() as $case) {
            $labels[$case->value] = method_exists($case, 'label')
                ? $case->label()
                : str_replace('_', ' ', ucfirst($case->value));
        }

        return $labels;
    }

    /**
     * Check if a given value is valid for this enum
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Try to create enum from value, return null if invalid
     */
    public static function tryFromValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom($value);
    }

    /**
     * Get label for display (default implementation)
     *
     * Override this method in your enum for custom labels.
     */
    public function label(): string
    {
        return str_replace('_', ' ', ucfirst($this->value));
    }

    /**
     * Get random enum case
     */
    public static function random(): self
    {
        $cases = self::cases();

        return $cases[array_rand($cases)];
    }
}
