<?php

namespace App\Exceptions;

/**
 * A file operation would violate the resource composition invariants
 * (docs/RESOURCE_MODEL.md): single canonical, no components beside a
 * canonical, canonical carries no relation, at most one snapshot.
 */
class InvalidResourceComposition extends \RuntimeException {}
