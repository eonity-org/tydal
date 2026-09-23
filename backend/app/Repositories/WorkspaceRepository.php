<?php

namespace App\Repositories;

use App\Models\Workspace;
use App\Repositories\Interfaces\WorkspaceRepositoryInterface;

class WorkspaceRepository extends BaseRepository implements WorkspaceRepositoryInterface
{
    /**
     * WorkspaceRepository constructor.
     */
    public function __construct(Workspace $model)
    {
        parent::__construct($model);
    }
}
