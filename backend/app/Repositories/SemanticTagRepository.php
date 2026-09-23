<?php

namespace App\Repositories;

use App\Models\SemanticTag;
use App\Repositories\Interfaces\SemanticTagRepositoryInterface;

class SemanticTagRepository extends BaseRepository implements SemanticTagRepositoryInterface
{
    /**
     * SemanticTagRepository constructor.
     */
    public function __construct(SemanticTag $model)
    {
        parent::__construct($model);
    }
}
