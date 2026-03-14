<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Model\FieldTriangle;
use Elkuku\MaxfieldBundle\Model\Graph;
use Elkuku\MaxfieldBundle\Model\Plan;

/**
 * Generates a single candidate field plan.
 * Mirrors generator.py Generator::generate().
 */
class Generator
{
    private const N_REORDER_ATTEMPTS = 100;
    private const AP_PER_PORTAL = 1750;
    private const AP_PER_LINK = 313;
    private const AP_PER_FIELD = 1250;

    public function __construct(
        private readonly Fielder $fielder,
        private readonly LinkReorderer $reorderer,
    ) {
    }

    /**
     * Generate one candidate graph plan and score it.
     * Returns a populated Graph with ap, length, maxKeys, etc. set.
     */
    public function generate(Plan $plan): Graph
    {
        $graph = $plan->graph->deepClone();

        // Run recursive field generation
        if (!$this->fielder->makeFields($graph, $plan->perimPortals, $plan->portalsGno)) {
            $graph->ap = -1;
            $graph->length = INF;
            $graph->maxKeys = PHP_INT_MAX;
            return $graph;
        }

        // Assign fields/dependencies for all first-gen fields
        foreach ($graph->firstgenFields as $fld) {
            $fld->assignFieldsToLinks($graph);
        }

        // Reorder by origin
        $this->reorderer->reorderByOrigin($graph);
        $this->reorderer->reset($graph);

        // Reorder by dependencies (iterative improvement)
        $tries = 0;
        while (
            $this->reorderer->reorderByDependencies($graph, $plan->portalsDists)
            && $tries < self::N_REORDER_ATTEMPTS
        ) {
            $this->reorderer->reset($graph);
            $tries++;
        }

        // Compute max keys needed
        $destinationCount = array_fill(0, \count($plan->portals), 0);
        foreach ($graph->getEdges() as $link) {
            $destinationCount[$link->destination]++;
        }
        $maxKeys = 0;
        foreach ($plan->portals as $i => $portal) {
            $need = $destinationCount[$i] - $portal->keys;
            if ($need > $maxKeys) {
                $maxKeys = $need;
            }
        }
        $graph->maxKeys = max(0, $maxKeys);

        // Compute fields
        $numFields = 0;
        foreach ($graph->getEdges() as $link) {
            $numFields += \count($link->fields);
        }

        $graph->numLinks = \count($graph->getEdges());
        $graph->numFields = $numFields;
        $graph->length = $this->reorderer->getPathLength($graph, $plan->portalsDists);

        $graph->apPortals = self::AP_PER_PORTAL * \count($plan->portals);
        $graph->apLinks = self::AP_PER_LINK * $graph->numLinks;
        $graph->apFields = self::AP_PER_FIELD * $graph->numFields;
        $graph->ap = $graph->apPortals + $graph->apLinks + $graph->apFields;

        return $graph;
    }
}
