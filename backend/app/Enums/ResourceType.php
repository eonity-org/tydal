<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * Resource Type Enum
 *
 * Defines all resource types in the system.
 * Used in resources.type and collections.type database columns.
 */
enum ResourceType: string
{
    use HasEnumHelpers;

    case DOCUMENT = 'document';
    case VIDEO = 'video';
    case IMAGE = 'image';
    case AUDIO = 'audio';
    case URL = 'url';
    case MULTIMEDIA = 'multimedia';
    case COURSE = 'course';
    case ASSESSMENT = 'assessment';
    case ACTIVITY = 'activity';
    case BOOK = 'book';

    /**
     * Get multimedia resource types
     *
     * These types are typically normalized to 'multimedia' when checking
     * collection acceptance rules.
     *
     * @return array<string>
     */
    public static function multimediaTypes(): array
    {
        return [
            self::MULTIMEDIA->value,
            self::IMAGE->value,
            self::VIDEO->value,
            self::AUDIO->value,
        ];
    }

    /**
     * Get educational content types
     *
     * @return array<string>
     */
    public static function educationalTypes(): array
    {
        return [
            self::COURSE->value,
            self::ASSESSMENT->value,
            self::ACTIVITY->value,
            self::BOOK->value,
        ];
    }

    /**
     * Get document types
     *
     * @return array<string>
     */
    public static function documentTypes(): array
    {
        return [
            self::DOCUMENT->value,
            self::URL->value,
        ];
    }

    /**
     * Check if this type is a multimedia type
     */
    public function isMultimedia(): bool
    {
        return in_array($this->value, self::multimediaTypes(), true);
    }

    /**
     * Check if this type is educational content
     */
    public function isEducational(): bool
    {
        return in_array($this->value, self::educationalTypes(), true);
    }

    /**
     * Check if this type should be normalized to multimedia
     *
     * Image, audio, and video are normalized to 'multimedia' when
     * checking if a collection accepts this resource type.
     */
    public function normalizedForCollection(): string
    {
        return match ($this) {
            self::IMAGE, self::VIDEO, self::AUDIO => self::MULTIMEDIA->value,
            default => $this->value,
        };
    }

    /**
     * Get label for display
     */
    public function label(): string
    {
        return match ($this) {
            self::DOCUMENT => 'Document',
            self::VIDEO => 'Video',
            self::IMAGE => 'Image',
            self::AUDIO => 'Audio',
            self::URL => 'URL',
            self::MULTIMEDIA => 'Multimedia',
            self::COURSE => 'Course',
            self::ASSESSMENT => 'Assessment',
            self::ACTIVITY => 'Activity',
            self::BOOK => 'E-book',
        };
    }
}
