<?php

namespace App\Models\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    /**
     * Boot function to add UUID generation.
     * Uses UUID v7 (time-ordered) for better index performance.
     */
    protected static function bootHasUuid()
    {
        static::creating(function ($model) {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), (string) Str::orderedUuid());
            }
        });
    }
}
