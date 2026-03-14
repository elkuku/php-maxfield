<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Model;

/**
 * Represents a triangular Ingress field with three portal vertices.
 * Mirrors field.py Field class.
 */
class FieldTriangle
{
    /** @var list<FieldTriangle> */
    public array $children = [];

    /** @var list<int> Portal indices contained within this field */
    public array $contents = [];

    public ?int $splitter = null;

    /**
     * @param int[] $vertices Three portal indices; vertices[0] is the "anchor/nose" portal
     */
    public function __construct(
        public array $vertices,
        public bool $exterior = false,
    ) {
    }

    /**
     * Find portals within this field (barycentric coordinates).
     * @param float[][] $portalsGno Gnomonic projection of all portals (Nx2)
     */
    public function getContents(array $portalsGno): void
    {
        $v = [
            $portalsGno[$this->vertices[0]],
            $portalsGno[$this->vertices[1]],
            $portalsGno[$this->vertices[2]],
        ];

        // Signed area
        $area = 0.5 * (
            -$v[1][1] * $v[2][0]
            + $v[0][1] * (-$v[1][0] + $v[2][0])
            + $v[0][0] * ($v[1][1] - $v[2][1])
            + $v[1][0] * $v[2][1]
        );

        $sign = $area < 0 ? -1 : 1;

        $sParts = [
            $v[0][1] * $v[2][0] - $v[0][0] * $v[2][1],
            $v[2][1] - $v[0][1],
            $v[0][0] - $v[2][0],
        ];
        $tParts = [
            $v[0][0] * $v[1][1] - $v[0][1] * $v[1][0],
            $v[0][1] - $v[1][1],
            $v[1][0] - $v[0][0],
        ];

        $this->contents = [];
        foreach ($portalsGno as $i => $pg) {
            if (\in_array($i, $this->vertices, true)) {
                continue;
            }
            $s = $sign * ($sParts[0] + $sParts[1] * $pg[0] + $sParts[2] * $pg[1]);
            $t = $sign * ($tParts[0] + $tParts[1] * $pg[0] + $tParts[2] * $pg[1]);
            if ($s > 0 && $t > 0 && ($s + $t) < 2.0 * $area * $sign) {
                $this->contents[] = $i;
            }
        }
    }

    /**
     * Split this field on a random interior portal, updating splitter and children.
     */
    public function split(): void
    {
        if (empty($this->contents)) {
            return;
        }
        $idx = array_rand($this->contents);
        $this->splitter = $this->contents[$idx];

        $s = $this->splitter;
        $fld0 = new FieldTriangle([$s, $this->vertices[1], $this->vertices[2]], exterior: true);
        $fld1 = new FieldTriangle([$this->vertices[0], $this->vertices[1], $s], exterior: false);
        $fld2 = new FieldTriangle([$this->vertices[0], $this->vertices[2], $s], exterior: false);
        $this->children = [$fld0, $fld1, $fld2];
    }

    /**
     * Build all non-final links within this field recursively.
     * @throws \RuntimeException on DeadendError
     */
    public function buildLinks(Graph $graph, array $portalsGno): void
    {
        // Check if already completed by neighbours
        if (
            ($graph->hasEdge($this->vertices[0], $this->vertices[1]) || $graph->hasEdge($this->vertices[1], $this->vertices[0]))
            && ($graph->hasEdge($this->vertices[0], $this->vertices[2]) || $graph->hasEdge($this->vertices[2], $this->vertices[0]))
        ) {
            throw new \RuntimeException('DeadendError: Final vertex completed by neighbor(s)');
        }

        if (empty($this->contents)) {
            $this->getContents($portalsGno);
        }
        $this->split();

        if (empty($this->children)) {
            $graph->addLink($this->vertices[2], $this->vertices[1], reversible: true);
        } else {
            $this->children[0]->buildLinks($graph, $portalsGno);
            $this->children[0]->buildFinalLinks($graph, $portalsGno);
            $this->children[1]->buildLinks($graph, $portalsGno);
            $this->children[2]->buildLinks($graph, $portalsGno);
        }
    }

    /**
     * Build final "jet" links for this field.
     */
    public function buildFinalLinks(Graph $graph, array $portalsGno): void
    {
        if ($this->exterior) {
            $graph->addLink($this->vertices[1], $this->vertices[0], reversible: true);
            $graph->addLink($this->vertices[2], $this->vertices[0], reversible: true);
        } else {
            $graph->addLink($this->vertices[0], $this->vertices[1], reversible: false);
            $graph->addLink($this->vertices[0], $this->vertices[2], reversible: false);
        }

        if (!empty($this->children)) {
            $this->children[1]->buildFinalLinks($graph, $portalsGno);
            $this->children[2]->buildFinalLinks($graph, $portalsGno);
        }
    }

    /**
     * Assign field completion and dependency information to graph edges.
     */
    public function assignFieldsToLinks(Graph $graph): void
    {
        // Get all three directed edges for this field
        $links = [];
        foreach (self::permutations($this->vertices) as [$a, $b]) {
            if ($graph->hasEdge($a, $b)) {
                $links[] = [$a, $b];
            }
        }
        if (\count($links) !== 3) {
            throw new \RuntimeException('Field does not have three edges!');
        }

        // Find last link (highest order)
        $orders = array_map(fn($l) => $graph->getLink($l[0], $l[1])->order, $links);
        $lastIdx = array_keys($orders, max($orders))[0];
        $lastLink = $links[$lastIdx];

        $graph->getLink($lastLink[0], $lastLink[1])->fields[] = $this->vertices;

        if (!$this->exterior) {
            // All other links are dependencies
            foreach ($links as $i => $l) {
                if ($i !== $lastIdx) {
                    $graph->getLink($lastLink[0], $lastLink[1])->depends[] = $l;
                }
            }
        } elseif (!empty($this->children)) {
            // Only the link opposite the anchor is a dependency
            foreach ($links as $l) {
                if (!\in_array($this->vertices[0], $l, true)) {
                    $graph->getLink($lastLink[0], $lastLink[1])->depends[] = $l;
                    break;
                }
            }
        }

        // Recurse to children
        foreach ($this->children as $child) {
            $child->assignFieldsToLinks($graph);
        }

        // All interior portals must be done first
        foreach ($this->contents as $p) {
            $graph->getLink($lastLink[0], $lastLink[1])->depends[] = $p;
        }
    }
    /**
     * Generate all 2-permutations of an array.
     * @param int[] $arr
     * @return array<int[]>
     */
    private static function permutations(array $arr): array
    {
        $result = [];
        $n = \count($arr);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($i !== $j) {
                    $result[] = [$arr[$i], $arr[$j]];
                }
            }
        }
        return $result;
    }
}
