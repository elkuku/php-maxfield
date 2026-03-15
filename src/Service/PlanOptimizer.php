<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Model\Graph;
use Elkuku\MaxfieldBundle\Model\Plan;
use Elkuku\MaxfieldBundle\Model\Portal;

/**
 * Runs multiple Generator iterations and picks the best plan.
 * Mirrors plan.py Plan::optimize().
 */
class PlanOptimizer
{
    public function __construct(
        private readonly Generator $generator,
        private readonly GeometryService $geometry,
    ) {
    }

    /**
     * Initialise plan geometry (coordinates, hull, graph nodes).
     *
     * @param Portal[] $portals
     */
    public function initPlan(Plan $plan): void
    {
        // Build lon/lat in radians
        $portalsLL = array_map(
            fn(Portal $p) => [deg2rad($p->lon), deg2rad($p->lat)],
            $plan->portals
        );

        $plan->portalsDists = $this->geometry->calcSphericalDistances($portalsLL);
        $plan->portalsGno = $this->geometry->gnomonicProjection($portalsLL);
        [$plan->portalsMer, $plan->zoom, $plan->llCenter] = $this->geometry->webMercatorProjection($portalsLL, 640);

        // Convex hull
        $plan->perimPortals = $this->geometry->convexHull($plan->portalsGno);

        // Add nodes to graph
        foreach ($plan->portals as $i => $portal) {
            $plan->graph->addNode($i, $portal->sbul, $portal->keys);
        }
    }

    /**
     * Run $numIterations candidate plans and select the best by:
     *   1. Maximum AP (descending)
     *   2. Minimum single-agent walking distance (ascending)
     *   3. Minimum keys needed (ascending)
     *
     * Saves the best graph to $plan->graph.
     */
    public function optimize(Plan $plan, int $numIterations = 100, bool $verbose = false): void
    {
        $results = [];
        $progressInterval = max(1, (int)($numIterations / 10));

        for ($i = 0; $i < $numIterations; $i++) {
            $results[] = $this->generator->generate($plan);

            if ($verbose && ($i + 1) % $progressInterval === 0) {
                printf("  Iteration %d/%d (%.0f%%)\n", $i + 1, $numIterations, ($i + 1) / $numIterations * 100);
            }
        }

        if ($verbose && $numIterations % $progressInterval !== 0) {
             printf("  Iteration %d/%d (100%%)\n", $numIterations, $numIterations);
        }

        usort($results, fn(Graph $a, Graph $b) => $this->comparePlans($a, $b));
        $plan->graph = $results[0];
    }

    private function comparePlans(Graph $a, Graph $b): int
    {
        // Sort by (-ap, length, maxKeys)
        if ($a->ap !== $b->ap) {
            return $b->ap <=> $a->ap; // descending AP
        }
        if ($a->length !== $b->length) {
            return $a->length <=> $b->length; // ascending length
        }
        return $a->maxKeys <=> $b->maxKeys; // ascending keys
    }
}
