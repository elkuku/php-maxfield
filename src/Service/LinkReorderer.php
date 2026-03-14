<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Model\Graph;

/**
 * Reorders links in a completed plan graph to minimize single-agent walking distance.
 * Mirrors reorder.py functions reorder_links_origin / reorder_links_depends / get_path_length.
 */
class LinkReorderer
{
    /**
     * Re-assign link orders so that links with the same origin are grouped consecutively.
     * Mirrors reorder_links_origin().
     */
    public function reorderByOrigin(Graph $graph): void
    {
        $orderedLinks = $graph->getOrderedLinks();
        if (empty($orderedLinks)) {
            return;
        }

        $newOrder = [];
        $used = array_fill(0, \count($orderedLinks), false);
        $n = \count($orderedLinks);

        // Greedy: pick next link that shares origin with current, or nearest unvisited
        $current = 0; // start with first link
        $newOrder[] = $current;
        $used[$current] = true;

        for ($i = 1; $i < $n; $i++) {
            $curOrigin = $orderedLinks[$current]->origin;
            // Find unused link with same origin
            $found = null;
            for ($j = 0; $j < $n; $j++) {
                if (!$used[$j] && $orderedLinks[$j]->origin === $curOrigin) {
                    $found = $j;
                    break;
                }
            }
            if ($found === null) {
                // Pick any unused link
                for ($j = 0; $j < $n; $j++) {
                    if (!$used[$j]) {
                        $found = $j;
                        break;
                    }
                }
            }
            if ($found === null) {
                break;
            }
            $newOrder[] = $found;
            $used[$found] = true;
            $current = $found;
        }

        // Reassign order values
        foreach ($newOrder as $newPos => $oldIdx) {
            $orderedLinks[$oldIdx]->order = $newPos;
        }

        // Update linkOrder to match
        $graph->linkOrder = array_map(
            fn($idx) => $orderedLinks[$idx]->asPair(),
            $newOrder
        );
    }

    /**
     * Try swapping blocks of links to find a shorter path.
     * Returns true if any improvement was found.
     * Mirrors reorder_links_depends().
     */
    public function reorderByDependencies(Graph $graph, array $dists): bool
    {
        $links = $graph->getOrderedLinks();
        $n = \count($links);
        if ($n < 2) {
            return false;
        }

        $currentLen = $this->getPathLength($graph, $dists);
        $improved = false;

        // Try swapping pairs of adjacent links that don't have dependency conflicts
        for ($i = 0; $i < $n - 1; $i++) {
            $linkI = $links[$i];
            $linkJ = $links[$i + 1];

            // Check if swapping is allowed (no dependency of j on i)
            if ($this->dependsOn($linkJ, $linkI)) {
                continue;
            }
            if ($this->dependsOn($linkI, $linkJ)) {
                continue;
            }

            // Temporarily swap orders
            $linkI->order = $i + 1;
            $linkJ->order = $i;
            // Update linkOrder
            $graph->linkOrder[$i] = $linkJ->asPair();
            $graph->linkOrder[$i + 1] = $linkI->asPair();

            $newLen = $this->getPathLength($graph, $dists);
            if ($newLen < $currentLen) {
                $improved = true;
                $currentLen = $newLen;
                // Refresh $links array for next iteration
                $links = $graph->getOrderedLinks();
            } else {
                // Revert
                $linkI->order = $i;
                $linkJ->order = $i + 1;
                $graph->linkOrder[$i] = $linkI->asPair();
                $graph->linkOrder[$i + 1] = $linkJ->asPair();
            }
        }

        return $improved;
    }

    /**
     * Compute total single-agent walking distance for this link order.
     */
    public function getPathLength(Graph $graph, array $dists): float
    {
        $links = $graph->getOrderedLinks();
        $n = \count($links);
        if ($n === 0) {
            return 0.0;
        }

        $total = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $from = $links[$i]->origin;
            $to = $links[$i + 1]->origin;
            $total += $dists[$from][$to] ?? 0;
        }
        return $total;
    }

    /**
     * Reset field/dependency data after reordering and recompute from firstgenFields.
     */
    public function reset(Graph $graph): void
    {
        foreach ($graph->getEdges() as $link) {
            $link->fields = [];
            $link->depends = [];
        }
        foreach ($graph->firstgenFields as $fld) {
            $fld->assignFieldsToLinks($graph);
        }
    }

    private function dependsOn(\Elkuku\MaxfieldBundle\Model\Link $link, \Elkuku\MaxfieldBundle\Model\Link $other): bool
    {
        $otherPair = $other->asPair();
        foreach ($link->depends as $dep) {
            if (\is_array($dep) && $dep === $otherPair) {
                return true;
            }
            // portal index dependency — can't swap if same origin
            if (\is_int($dep) && $dep === $other->origin) {
                return true;
            }
        }
        return false;
    }
}
