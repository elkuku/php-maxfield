<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Model;

/**
 * Represents a directed link between two portals in the plan graph.
 */
class Link
{
    /** @var list<int[]> Fields (as vertex triple arrays) completed by this link */
    public array $fields = [];

    /** @var list<array{int,int}|int> Dependencies (links or portal indices) that must be done first */
    public array $depends = [];

    public function __construct(
        public readonly int $origin,
        public readonly int $destination,
        public int $order,
        public bool $reversible = false,
    ) {
    }

    public function key(): string
    {
        return $this->origin.','.$this->destination;
    }

    public function asPair(): array
    {
        return [$this->origin, $this->destination];
    }
}
