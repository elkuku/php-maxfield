<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Model;

/**
 * One agent link assignment.
 */
class Assignment
{
    public function __construct(
        public readonly int $agent,
        public readonly int $location,
        public readonly int $arrive,
        public readonly int $link,
        public readonly int $depart,
    ) {
    }
}
