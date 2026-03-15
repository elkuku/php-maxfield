# Maxfield PHP Symfony Bundle - Implementation Plan

Port of the Python [Ingress Maxfield](https://github.com/tvwenger/maxfield) tool (v4.0) to a reusable **Symfony Bundle** (`MaxfieldBundle`). The bundle provides services, a console command, and data models to generate Ingress linking and fielding strategy plans.

## Key Design Decisions

> [!IMPORTANT]
> The Python code uses **numpy** (matrix math), **networkx** (directed graphs), **scipy** (ConvexHull), and **ortools** (vehicle routing for multi-agent).
> The PHP port will:
> - Implement all math natively in PHP (no external C libraries needed)
> - Use a simple adjacency-list graph (SplDoublyLinkedList or plain arrays)
> - Implement ConvexHull via Graham scan (pure PHP)
> - **Single-agent routing**: trivially already ordered→ exact match to Python
> - **Multi-agent routing (TSP)**: implement a greedy nearest-neighbor heuristic (dropping ortools dependency). This means multi-agent route quality may be lower than the Python tool.
> - **Output**: text files only (no PNG/GIF — those require matplotlib/imageio)

> [!NOTE]  
> Map image generation (portal_map.png, link_map.png, plan_movie.gif) is **deferred** — the bundle will output text/CSV files and JSON data; image generation can be added using a separate library (e.g. GD/Imagick) later.

---

## Proposed Changes

### Bundle Root

#### [NEW] `composer.json`
Defines the package as `elkuku/maxfield-bundle` (PHP 8.1+, Symfony 6+).

#### [NEW] `MaxfieldBundle.php`
The bundle class, registers the DI extension.

#### [NEW] `DependencyInjection/MaxfieldExtension.php`
Loads `services.yaml`, autowires all bundle services.

#### [NEW] `Resources/config/services.yaml`
Autowires all services, tags the console command.

---

### Domain Models (`src/Model/`)

#### [NEW] `Portal.php`
Value object: `name`, `lat`, `lon`, `keys` (int), `sbul` (bool).

#### [NEW] `Link.php`
Represents a directed link between two portal indices: `origin`, `destination`, `order`, `reversible`, `fields[]`, `depends[]`.

#### [NEW] `FieldTriangle.php`
Represents a three-portal field: `vertices[3]`, `exterior`, `children[]`, `contents[]`, `splitter`.

#### [NEW] `Graph.php`
Adjacency-list directed graph wrapping `Link[]`. Provides: `addEdge()`, `hasEdge()`, `removeEdge()`, `outDegree()`, `inDegree()`, `getEdges()`, `linkOrder[]`, `firstgenFields[]`, graph-level stats (`numLinks`, `numFields`, `maxKeys`, `ap`, etc.).

#### [NEW] `Plan.php`
Holds `Portal[]`, `Graph`, `numAgents`, distances matrix, gnomonic coords, mercator coords, convex hull vertices, assignments.

#### [NEW] `Assignment.php`
One routing assignment: `agent`, `location`, `arrive`, `link`, `depart`.

---

### Services (`src/Service/`)

#### [NEW] `PortalFileParser.php`
Parses semicolon-delimited portal text files (same format as Python). Returns `Portal[]`. Handles comments, SBUL flag, keys count, Intel URL `pll=` extraction, duplicate detection.

#### [NEW] `GeometryService.php`
Pure PHP math:
- `calcSphericalDistances(array $portalsLL): array` — Vincenty formula, returns NxN matrix (meters, rounded to int)
- `gnomonicProjection(array $portalsLL): array` — returns Nx2 array
- `webMercatorProjection(array $portalsLL): array` — returns [xy, zoom, center]
- `convexHull(array $points): array` — Graham scan, returns vertex indices

#### [NEW] `FieldBuilder.php` (maps to `field.py`)
`addLink(Graph $graph, int $p1, int $p2, bool $reversible): void` — enforces outgoing link limits (8 or 24 for SBUL), tries reversal.
`FieldTriangle::buildLinks()`, `buildFinalLinks()`, `assignFieldsToLinks()` are methods on `FieldTriangle`.

#### [NEW] `Fielder.php` (maps to `fielder.py`)
Recursively generates fields to fill the convex hull. `makeFields(Graph $graph, array $perimPortals, array $portalsGno): bool`.

#### [NEW] `Generator.php` (maps to `generator.py`)
Generates a single candidate field plan: deep-copies graph, runs `Fielder`, reorders links, computes stats (AP, maxKeys, length). `generate(Plan $plan): Graph`.

#### [NEW] `PlanOptimizer.php` (maps to `plan.py` `optimize()`)
Runs `$numIterations` calls to `Generator::generate()`, picks the best by `(-AP, length, maxKeys)`.

#### [NEW] `LinkReorderer.php` (maps to `reorder.py`)
`reorderByOrigin(Graph $graph): void` — group consecutive links with the same origin.
`reorderByDependencies(Graph $graph, array $dists): bool` — move blocks to minimize walking; returns true if improved.
`getPathLength(Graph $graph, array $dists): int`.
`reset(Graph $graph): void` — clears field/dependency assignments and recomputes from `firstgenFields`.

#### [NEW] `AgentRouter.php` (maps to `router.py`)
- Single-agent: trivial ordered walk, same as Python.
- Multi-agent: greedy nearest-neighbor with dependency constraints.
Returns `Assignment[]`.

#### [NEW] `ResultsGenerator.php` (maps to `results.py`, text outputs only)
Generates:
- `key_preparation.txt` / `.csv`
- `ownership_preparation.txt`
- `agent_key_preparation.txt` / `.csv`
- `agent_assignments.txt` / `.csv`
- `agent_N_assignment.txt` for each agent

---

### Console Command (`src/Command/`)

#### [NEW] `MaxfieldPlanCommand.php`
`maxfield:plan` — mirrors Python `maxfield-plan` CLI:
```
php bin/console maxfield:plan <filename> [options]
  --num-agents=1
  --num-iterations=1000
  --max-route-solutions=1000
  --max-route-runtime=60
  --outdir=.
  --output-csv
  --verbose
  --res-colors
```

---

## Verification Plan

### Automated Tests (PHPUnit)

Install PHPUnit via composer dev dependency. Run with:
```bash
cd /home/elkuku/repos/maxfield-php-2
./vendor/bin/phpunit
```

#### [NEW] `tests/Service/PortalFileParserTest.php`
- Parse `example_portals.txt` → assert 18 portals, correct lat/lon/name

#### [NEW] `tests/Service/GeometryServiceTest.php`
- `calcSphericalDistances`: spot-check distance between two known coords
- `gnomonicProjection`: verify output dimensionality and centre at origin
- `convexHull`: known point set → known hull vertices

#### [NEW] `tests/Model/GraphTest.php`
- `addEdge`, `hasEdge`, `removeEdge`, `outDegree` basic correctness

### Integration / Console Test

```bash
cd /home/elkuku/repos/maxfield-php-2
php bin/console maxfield:plan /home/elkuku/repos/maxfield/example/example_portals.txt \
    --num-agents=1 --num-iterations=50 --outdir=/tmp/maxfield-out --output-csv --verbose
```

Expected output (varies due to randomness, but structure should match):
- Console prints portal count, plan stats (portals, links, fields, AP)
- Files created: `key_preparation.txt`, `ownership_preparation.txt`, `agent_key_preparation.txt`, `agent_assignments.txt`, `agent_1_assignment.txt`
- With `--output-csv`: corresponding `.csv` files

### Manual Verification

1. Run the console command above
2. Open `/tmp/maxfield-out/key_preparation.txt` — confirm portal names and key counts
3. Open `/tmp/maxfield-out/agent_assignments.txt` — confirm link order entries exist
4. Compare AP total with Python reference (~95585 for 18 portals, may vary)
