<?php

namespace App\Enums;

/**
 * Who asserted a graph edge (Epic 4.3). Curator edges are `manual`;
 * `tags` (co-occurrence) and `semantic` (embedding k-NN) edges are
 * rebuildable — `graph:rebuild` drops and recreates only its own origin,
 * never a curator's work.
 */
enum ResourceRelationOrigin: string
{
    case MANUAL = 'manual';
    case TAGS = 'tags';
    case SEMANTIC = 'semantic';
}
