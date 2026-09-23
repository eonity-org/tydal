<?php

namespace App\Repositories;

use App\Models\Vault;
use App\Repositories\Interfaces\VaultRepositoryInterface;

class VaultRepository extends BaseRepository implements VaultRepositoryInterface
{
    public function __construct(Vault $model)
    {
        parent::__construct($model);
    }
}
