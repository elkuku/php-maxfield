<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Exception\DeadendException;
use Elkuku\MaxfieldBundle\Model\FieldTriangle;
use Elkuku\MaxfieldBundle\Model\Graph;

/**
 * Recursively generates fields to fill the convex hull.
 * Mirrors fielder.py Fielder class.
 */
class Fielder
{
    private const N_FIELD_ATTEMPTS = 100;

    /**
     * Attempt to generate fields covering all portals inside the convex hull.
     *
     * @param int[]   $perimPortals Indices of hull portals
     * @param float[][] $portalsGno  Gnomonic projection Nx2
     */
    public function makeFields(Graph $graph, array $perimPortals, array $portalsGno): bool
    {
        $numPerim = \count($perimPortals);
        if ($numPerim < 3) {
            return true;
        }

        // Record current state for potential rollback
        $numLinks = \count($graph->linkOrder);
        $numFirstgen = \count($graph->firstgenFields);

        // Try random permutations of perimeter portals
        $indices = range(0, $numPerim - 1);
        shuffle($indices);

        foreach ($indices as $i) {
            // Pick three consecutive hull portals (use modulo to wrap like Python numpy)
            $prevIdx = ($i - 1 + $numPerim) % $numPerim;
            $nextIdx = ($i + 1) % $numPerim;
            $triIndices = [$perimPortals[$i], $perimPortals[$prevIdx], $perimPortals[$nextIdx]];
            shuffle($triIndices); // randomize vertex order
            $fld = new FieldTriangle($triIndices, exterior: true);

            // Try to build fields within this triangle
            $success = false;
            for ($attempt = 0; $attempt < self::N_FIELD_ATTEMPTS; $attempt++) {
                try {
                    $fld->buildLinks($graph, $portalsGno);
                    $fld->buildFinalLinks($graph, $portalsGno);
                    $success = true;
                    break;
                } catch (DeadendException|\RuntimeException $e) {
                    $this->rollback($graph, $numLinks, $numFirstgen);
                    // reset field state for retry
                    $fld = new FieldTriangle($triIndices, exterior: true);
                }
            }

            if (!$success) {
                continue;
            }

            // Recurse, removing current pivot from perimeter
            $newPerim = array_values(array_filter($perimPortals, fn($p) => $p !== $perimPortals[$i]));
            if (!$this->makeFields($graph, $newPerim, $portalsGno)) {
                $this->rollback($graph, $numLinks, $numFirstgen);
                continue;
            }

            $graph->firstgenFields[] = $fld;
            return true;
        }

        return false;
    }

    private function rollback(Graph $graph, int $numLinks, int $numFirstgen): void
    {
        // Remove extra links
        $toRemove = \array_slice($graph->linkOrder, $numLinks);
        $graph->linkOrder = \array_slice($graph->linkOrder, 0, $numLinks);
        foreach ($toRemove as [$origin, $dest]) {
            $graph->removeEdge($origin, $dest);
        }
        // Trim firstgen fields
        $graph->firstgenFields = \array_slice($graph->firstgenFields, 0, $numFirstgen);
    }
}
