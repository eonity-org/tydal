<?php

namespace App\Repositories;

use App\Models\Collection;
use App\Repositories\Interfaces\CollectionRepositoryInterface;

class CollectionRepository extends BaseRepository implements CollectionRepositoryInterface
{
    /**
     * CollectionRepository constructor.
     */
    public function __construct(Collection $model)
    {
        parent::__construct($model);
    }
}
