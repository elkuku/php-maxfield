<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Model\Assignment;
use Elkuku\MaxfieldBundle\Model\Graph;
use Elkuku\MaxfieldBundle\Model\Link;

/**
 * Assigns links to agents and computes routing.
 * Mirrors router.py Router class.
 *
 * Single-agent: trivial ordered walk (exact equivalent of Python).
 * Multi-agent: greedy nearest-neighbour heuristic with dependency enforcement
 *   (replaces Python's OR-Tools vehicle routing).
 */
class AgentRouter
{
    private const WALK_SPEED = 1;   // metres/second
    private const LINK_TIME  = 30;  // seconds per link
    private const COMM_TIME  = 30;  // seconds to communicate completed links

    /**
     * @return Assignment[]
     */
    public function routeAgents(
        Graph $graph,
        array $portalsDists,
        int $numAgents,
        int $maxRouteSolutions = 1000,
        int $maxRouteRuntime = 60,
    ): array {
        $orderedLinks = $graph->getOrderedLinks();

        if ($numAgents === 1) {
            return $this->singleAgentRoute($orderedLinks, $portalsDists);
        }

        return $this->multiAgentRoute($orderedLinks, $portalsDists, $numAgents, $maxRouteRuntime);
    }

    /**
     * Trivial single-agent route: just walk the ordered links in sequence.
     *
     * @param  Link[]  $orderedLinks
     * @return Assignment[]
     */
    private function singleAgentRoute(array $orderedLinks, array $dists): array
    {
        $assignments = [];
        $arrive = 0;
        $depart = 0;

        foreach ($orderedLinks as $i => $link) {
            if ($i === 0) {
                $arrive = 0;
            } else {
                $prev = $orderedLinks[$i - 1];
                $travelTime = (int) ($dists[$prev->origin][$link->origin] / self::WALK_SPEED);
                $arrive = $depart + $travelTime;
            }
            $depart = $arrive + self::LINK_TIME;
            $assignments[] = new Assignment(
                agent: 0,
                location: $link->origin,
                arrive: $arrive,
                link: $link->destination,
                depart: $depart,
            );
        }

        return $assignments;
    }

    /**
     * Greedy nearest-neighbour multi-agent routing with dependency enforcement.
     *
     * @param  Link[] $orderedLinks
     * @return Assignment[]
     */
    private function multiAgentRoute(
        array $orderedLinks,
        array $dists,
        int $numAgents,
        int $maxRuntime,
    ): array {
        $n = \count($orderedLinks);
        $done = array_fill(0, $n, false);
        $completedAt = array_fill(0, $n, -1); // time link i was depart-completed

        // Current state per agent: [position, available_at]
        $agents = [];
        for ($a = 0; $a < $numAgents; $a++) {
            $agents[$a] = ['pos' => $orderedLinks[0]->origin, 'available' => 0];
        }

        $assignments = [];
        $startTime = time();

        $remaining = $n;
        while ($remaining > 0) {
            if ((time() - $startTime) > $maxRuntime) {
                break;
            }

            // Find next link that has all dependencies satisfied
            $nextLinkIdx = null;
            for ($i = 0; $i < $n; $i++) {
                if ($done[$i]) {
                    continue;
                }
                if ($this->dependenciesMet($orderedLinks, $i, $completedAt)) {
                    $nextLinkIdx = $i;
                    break;
                }
            }
            if ($nextLinkIdx === null) {
                // All remaining links have unmet dependencies — pick agent with earliest availability
                $nextLinkIdx = $this->firstUnfinished($done, $n);
                if ($nextLinkIdx === null) {
                    break;
                }
            }

            // Find the idle agent that can reach this link's origin soonest
            $link = $orderedLinks[$nextLinkIdx];
            $bestAgent = null;
            $bestArrive = PHP_INT_MAX;
            foreach ($agents as $a => ['pos' => $pos, 'available' => $available]) {
                $travelTime = (int) ($dists[$pos][$link->origin] / self::WALK_SPEED);
                $arrive = max($available, $this->latestDependencyDepart($orderedLinks, $nextLinkIdx, $completedAt)) + $travelTime;
                if ($arrive < $bestArrive) {
                    $bestArrive = $arrive;
                    $bestAgent = $a;
                }
            }

            $arrive = $bestArrive;
            $depart = $arrive + self::LINK_TIME;
            $completedAt[$nextLinkIdx] = $depart;
            $done[$nextLinkIdx] = true;
            $remaining--;

            $agents[$bestAgent]['pos'] = $link->origin;
            $agents[$bestAgent]['available'] = $depart + self::COMM_TIME;

            $assignments[] = new Assignment(
                agent: $bestAgent,
                location: $link->origin,
                arrive: $arrive,
                link: $link->destination,
                depart: $depart,
            );
        }

        // Sort by arrival time
        usort($assignments, fn(Assignment $a, Assignment $b) => $a->arrive <=> $b->arrive);

        return $assignments;
    }

    /**
     * @param Link[] $links
     */
    private function dependenciesMet(array $links, int $idx, array $completedAt): bool
    {
        foreach ($links[$idx]->depends as $dep) {
            if (\is_array($dep)) {
                // Find the link matching this [origin, dest] pair
                foreach ($links as $j => $l) {
                    if ($l->origin === $dep[0] && $l->destination === $dep[1]) {
                        if ($completedAt[$j] < 0) {
                            return false;
                        }
                        break;
                    }
                }
            }
            // Portal index dependency: ignore for multi-agent (just ordering)
        }
        return true;
    }

    private function latestDependencyDepart(array $links, int $idx, array $completedAt): int
    {
        $latest = 0;
        foreach ($links[$idx]->depends as $dep) {
            if (\is_array($dep)) {
                foreach ($links as $j => $l) {
                    if ($l->origin === $dep[0] && $l->destination === $dep[1]) {
                        $latest = max($latest, $completedAt[$j] < 0 ? 0 : $completedAt[$j]);
                        break;
                    }
                }
            }
        }
        return $latest;
    }

    private function firstUnfinished(array $done, int $n): ?int
    {
        for ($i = 0; $i < $n; $i++) {
            if (!$done[$i]) {
                return $i;
            }
        }
        return null;
    }
}
