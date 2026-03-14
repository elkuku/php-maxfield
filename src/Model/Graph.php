<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Model;

use Elkuku\MaxfieldBundle\Exception\DeadendException;

/**
 * Directed graph holding links between portals, with outgoing-link-limit enforcement.
 * Mirrors the networkx.DiGraph usage in field.py / plan.py.
 */
class Graph
{
    private const OUTGOING_LIMIT = 8;
    private const OUTGOING_LIMIT_SBUL = 24; // Two SBULs deployed

    /** @var array<int, array{'sbul': bool, 'keys': int}> */
    private array $nodes = [];

    /** @var array<string, Link> key = "origin,dest" */
    private array $edges = [];

    /** @var list<int[]> ordered list of [origin, dest] pairs */
    public array $linkOrder = [];

    /** @var list<FieldTriangle> */
    public array $firstgenFields = [];

    // Plan statistics (populated by Generator)
    public int $numLinks = 0;
    public int $numFields = 0;
    public int $maxKeys = 0;
    public int $ap = -1;
    public int $apPortals = 0;
    public int $apLinks = 0;
    public int $apFields = 0;
    public float $length = INF;

    public function addNode(int $idx, bool $sbul = false, int $keys = 0): void
    {
        $this->nodes[$idx] = ['sbul' => $sbul, 'keys' => $keys];
    }

    public function getNode(int $idx): array
    {
        return $this->nodes[$idx] ?? ['sbul' => false, 'keys' => 0];
    }

    public function hasEdge(int $origin, int $dest): bool
    {
        return isset($this->edges["$origin,$dest"]);
    }

    public function getLink(int $origin, int $dest): Link
    {
        return $this->edges["$origin,$dest"];
    }

    /** @return list<Link> */
    public function getEdges(): array
    {
        return array_values($this->edges);
    }

    public function outDegree(int $portal): int
    {
        $count = 0;
        foreach ($this->edges as $link) {
            if ($link->origin === $portal) {
                $count++;
            }
        }
        return $count;
    }

    public function inDegree(int $portal): int
    {
        $count = 0;
        foreach ($this->edges as $link) {
            if ($link->destination === $portal) {
                $count++;
            }
        }
        return $count;
    }

    public function canAddOutbound(int $portal): bool
    {
        $max = $this->nodes[$portal]['sbul'] ? self::OUTGOING_LIMIT_SBUL : self::OUTGOING_LIMIT;
        return $this->outDegree($portal) < $max;
    }

    /**
     * Add a link, respecting outgoing-link limits. Mirrors field.py add_link().
     * @throws DeadendException
     */
    public function addLink(int $p1, int $p2, bool $reversible = false): void
    {
        // Skip if either direction already exists
        if ($this->hasEdge($p1, $p2) || $this->hasEdge($p2, $p1)) {
            return;
        }

        $numLinks = \count($this->linkOrder);

        if ($this->canAddOutbound($p1)) {
            $this->setEdge($p1, $p2, $numLinks, $reversible);
            return;
        }

        if ($reversible && $this->canAddOutbound($p2)) {
            $this->setEdge($p2, $p1, $numLinks, $reversible);
            return;
        }

        // Try reversing one outgoing link from p1
        $p1Reversible = $this->findReversibleOutgoing($p1);
        if ($p1Reversible !== null) {
            $this->reverseEdge($p1, $p1Reversible);
            $this->setEdge($p1, $p2, $numLinks, $reversible);
            return;
        }

        // Try reversing one outgoing link from p2 (if link itself is reversible)
        if ($reversible) {
            $p2Reversible = $this->findReversibleOutgoing($p2);
            if ($p2Reversible !== null) {
                $this->reverseEdge($p2, $p2Reversible);
                $this->setEdge($p2, $p1, $numLinks, $reversible);
                return;
            }
        }

        throw new DeadendException('All portals have maximum outgoing links.');
    }

    public function removeEdge(int $origin, int $dest): void
    {
        unset($this->edges["$origin,$dest"]);
    }

    private function setEdge(int $origin, int $dest, int $order, bool $reversible): void
    {
        $link = new Link($origin, $dest, $order, $reversible);
        $this->edges["$origin,$dest"] = $link;
        $this->linkOrder[] = [$origin, $dest];
    }

    private function findReversibleOutgoing(int $portal): ?int
    {
        foreach ($this->edges as $link) {
            if ($link->origin === $portal && $link->reversible && $this->canAddOutbound($link->destination)) {
                return $link->destination;
            }
        }
        return null;
    }

    private function reverseEdge(int $origin, int $dest): void
    {
        $oldLink = $this->edges["$origin,$dest"];
        unset($this->edges["$origin,$dest"]);

        $newLink = new Link($dest, $origin, $oldLink->order, $oldLink->reversible);
        $newLink->fields = $oldLink->fields;
        $newLink->depends = $oldLink->depends;
        $this->edges["$dest,$origin"] = $newLink;

        // Update linkOrder
        foreach ($this->linkOrder as $k => $pair) {
            if ($pair[0] === $origin && $pair[1] === $dest) {
                $this->linkOrder[$k] = [$dest, $origin];
                break;
            }
        }
    }

    /**
     * Return links sorted by their order attribute.
     * @return list<Link>
     */
    public function getOrderedLinks(): array
    {
        $links = $this->getEdges();
        usort($links, fn(Link $a, Link $b) => $a->order <=> $b->order);
        return $links;
    }

    /**
     * Deep clone the graph (for use by Generator).
     */
    public function deepClone(): self
    {
        $clone = new self();
        $clone->nodes = $this->nodes;
        foreach ($this->edges as $key => $link) {
            $newLink = new Link($link->origin, $link->destination, $link->order, $link->reversible);
            $newLink->fields = $link->fields;
            $newLink->depends = $link->depends;
            $clone->edges[$key] = $newLink;
        }
        $clone->linkOrder = $this->linkOrder;
        // firstgenFields cloned separately after generation
        return $clone;
    }
}
