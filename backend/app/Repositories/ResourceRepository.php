<?php

namespace App\Repositories;

use App\Models\Resource;
use App\Repositories\Interfaces\ResourceRepositoryInterface;

class ResourceRepository extends BaseRepository implements ResourceRepositoryInterface
{
    /**
     * ResourceRepository constructor.
     */
    public function __construct(Resource $model)
    {
        parent::__construct($model);
    }
}
