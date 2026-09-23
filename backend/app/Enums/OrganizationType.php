<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * Organization Type Enum
 *
 * Defines the type of organization.
 */
enum OrganizationType: string
{
    use HasEnumHelpers;

    case INDIVIDUAL = 'individual';
    case BUSINESS = 'business';
    case EDUCATIONAL = 'educational';
    case GOVERNMENT = 'government';
    case NON_PROFIT = 'non_profit';

    /**
     * Get business organization types (all except individual)
     *
     * @return array<string>
     */
    public static function businessTypes(): array
    {
        return [
            self::BUSINESS->value,
            self::EDUCATIONAL->value,
            self::GOVERNMENT->value,
            self::NON_PROFIT->value,
        ];
    }

    /**
     * Check if this is a business/organizational type (not individual)
     */
    public function isBusinessType(): bool
    {
        return $this !== self::INDIVIDUAL;
    }

    /**
     * Get label for display
     */
    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Individual',
            self::BUSINESS => 'Business',
            self::EDUCATIONAL => 'Educational',
            self::GOVERNMENT => 'Government',
            self::NON_PROFIT => 'Non-Profit',
        };
    }
}
