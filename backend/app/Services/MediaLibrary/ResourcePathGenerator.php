<?php

namespace App\Services\MediaLibrary;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

class ResourcePathGenerator extends DefaultPathGenerator
{
    /**
     * Get the path for the given media.
     */
    public function getPath(Media $media): string
    {
        // Get the resource ID from the model
        $resourceId = $this->getResourceId($media);

        return "{$resourceId}/media/{$media->id}/";
    }

    /**
     * Get the path for conversions of the given media.
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media).'conversions/';
    }

    /**
     * Get the path for responsive images of the given media.
     */
    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media).'responsive-images/';
    }

    /**
     * Extract the resource ID from the media model.
     */
    protected function getResourceId(Media $media): string
    {
        // The model_type should be App\Models\Resource
        // The model_id should be the resource UUID
        return $media->model_id ?? 'unknown';
    }
}
