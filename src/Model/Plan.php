<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Model;

/**
 * Holds all data for one complete Maxfield plan.
 * Mirrors plan.py Plan class.
 */
class Plan
{
    /** @var Portal[] */
    public readonly array $portals;

    public int $numAgents;

    /** @var float[][] NxN spherical distance matrix (meters, int-cast) */
    public array $portalsDists = [];

    /** @var float[][] Nx2 gnomonic projection */
    public array $portalsGno = [];

    /** @var float[][] Nx2 web mercator projection */
    public array $portalsMer = [];

    public int $zoom = 15;

    /** @var float[] [lon, lat] center for web mercator */
    public array $llCenter = [0.0, 0.0];

    /** @var int[] Indices of portals on convex hull */
    public array $perimPortals = [];

    public Graph $graph;

    /** @var Assignment[] */
    public array $assignments = [];

    /**
     * @param Portal[] $portals
     */
    public function __construct(array $portals, int $numAgents = 1)
    {
        $this->portals = array_values($portals);
        $this->numAgents = $numAgents;
        $this->graph = new Graph();
    }
}
