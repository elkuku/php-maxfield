<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Model;

/**
 * Represents an Ingress portal with its coordinates and properties.
 */
class Portal
{
    public function __construct(
        public readonly string $name,
        public readonly float $lat,
        public readonly float $lon,
        public readonly int $keys = 0,
        public readonly bool $sbul = false,
    ) {
    }
}
