<?php

namespace App\Enums;

use App\Enums\Traits\HasEnumHelpers;

/**
 * Collection Type Enum
 *
 * Defines types of collections.
 * Note: This uses the same values as ResourceType but represents
 * collection categorization, not resource content type.
 *
 * Collections can accept specific resource types regardless of their own type.
 */
enum CollectionType: string
{
    use HasEnumHelpers;

    case DOCUMENT = 'document';
    case MULTIMEDIA = 'multimedia';
    case IMAGE = 'image';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case COURSE = 'course';
    case ASSESSMENT = 'assessment';
    case ACTIVITY = 'activity';
    case BOOK = 'book';
    case URL = 'url';

    /**
     * Get label for display
     */
    public function label(): string
    {
        return match ($this) {
            self::DOCUMENT => 'Document',
            self::MULTIMEDIA => 'Multimedia',
            self::IMAGE => 'Image',
            self::VIDEO => 'Video',
            self::AUDIO => 'Audio',
            self::COURSE => 'Course',
            self::ASSESSMENT => 'Assessment',
            self::ACTIVITY => 'Activity',
            self::BOOK => 'E-book',
            self::URL => 'URL',
        };
    }
}
