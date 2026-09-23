<?php

namespace App\Enums;

enum AityStatus: string
{
    case NOT_APPLICABLE = 'not_applicable';
    case QUEUED = 'queued';
    case AITY_IN_PROGRESS = 'aity_in_progress';
    case SUGGESTIONS_MADE = 'suggestions_made';
    case AUTOMATIC_REVIEW_DONE = 'automatic_review_done'; // every applied suggestion came from AITY auto-approve (applied_by_aity)
    case USER_REVIEW_DONE = 'user_review_done';

    public function label(): string
    {
        return match ($this) {
            self::NOT_APPLICABLE => 'Not applicable',
            self::QUEUED => 'Queued',
            self::AITY_IN_PROGRESS => 'Aity in progress',
            self::SUGGESTIONS_MADE => 'Suggestions ready',
            self::AUTOMATIC_REVIEW_DONE => 'Auto reviewed',
            self::USER_REVIEW_DONE => 'Reviewed',
        };
    }
}
