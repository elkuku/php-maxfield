# Maxfield PHP Symfony Bundle — Walkthrough

## What Was Built

A full Symfony Bundle (`elkuku/maxfield-bundle`) porting the Python [Ingress Maxfield v4.0](https://github.com/tvwenger/maxfield) tool to PHP 8.1+. The bundle generates linking and fielding strategy plans for Ingress operations.

---

## File Structure

```
maxfield-php-2/
├── composer.json
├── phpunit.xml.dist
├── integration_test.php
├── config/
│   └── services.yaml
└── src/
    ├── MaxfieldBundle.php
    ├── Command/
    │   └── MaxfieldPlanCommand.php        # `maxfield:plan` console command
    ├── DependencyInjection/
    │   └── MaxfieldExtension.php
    ├── Exception/
    │   └── DeadendException.php
    ├── Model/
    │   ├── Assignment.php                 # One agent→portal link assignment
    │   ├── FieldTriangle.php             # Triangle field; recursive build + dependency
    │   ├── Graph.php                      # Directed adjacency-list graph
    │   ├── Link.php                       # Directed link with order, fields, depends
    │   ├── Plan.php                       # Holds portals, geometry, graph, assignments
    │   └── Portal.php                     # Portal value object (name, lat, lon, keys, sbul)
    └── Service/
        ├── AgentRouter.php               # Single-agent walk + greedy multi-agent routing
        ├── Fielder.php                   # Recursive field generation w/ rollback
        ├── GeometryService.php           # Spherical distances, gnomonic/mercator proj, convex hull
        ├── Generator.php                 # Generates one candidate plan
        ├── LinkReorderer.php             # Reorder links to minimize walking distance
        ├── MaxfieldPlanner.php           # High-level pipeline facade
        ├── PlanOptimizer.php             # Runs N iterations, selects best plan
        ├── PortalFileParser.php          # Parses portal text file format
        └── ResultsGenerator.php          # Writes text/CSV output files
```

---

## Key Design Choices

| Python | PHP Equivalent |
|--------|---------------|
| `numpy` matrix math | Pure PHP loops/math |
| `networkx.DiGraph` | Custom `Graph` class (adjacency list) |
| `scipy.spatial.ConvexHull` | Graham scan in `GeometryService` |
| `ortools` vehicle routing | Greedy nearest-neighbour in `AgentRouter` |
| `matplotlib` / `imageio` | *(deferred — text/CSV output only)* |

---

## Tests — PHPUnit

```
PHPUnit 10.5.63  |  PHP 8.5.4
12 / 12 tests pass  |  26 assertions

Geometry Service
 ✔ Spherical distance same point
 ✔ Spherical distance known pair
 ✔ Spherical distance symmetric
 ✔ Gnomonic projection dimensions
 ✔ Convex hull square
 ✔ Convex hull with interior point

Portal File Parser
 ✔ Parse example file (18 portals)
 ✔ Parse string with keys
 ✔ Parse string with sbul
 ✔ Skips comment lines
 ✔ Skips duplicate coordinates
 ✔ Throws on missing url
```

---

## Integration Test — Python Reference vs PHP

Input: `example_portals.txt` (18 portals)

| Metric | Python Reference | PHP Output |
|--------|-----------------|------------|
| Portals | 18 | **18** ✓ |
| Links | 45 | **45** ✓ |
| Fields | 40 | **40** ✓ |
| TOTAL AP | 95,585 | **95,585** ✓ |
| Runtime | ~87s (1000 iters) | ~0.6s (50 iters) |

### Output Files Generated

| File | Size |
|------|------|
| `key_preparation.txt` | 1326 bytes |
| `key_preparation.csv` | 760 bytes |
| `ownership_preparation.txt` | 919 bytes |
| `agent_key_preparation.txt` | 865 bytes |
| `agent_key_preparation.csv` | 631 bytes |
| `agent_assignments.txt` | 4586 bytes |
| `agent_assignments.csv` | 3096 bytes |
| `agent_1_assignment.txt` | 4551 bytes |

---

## Console Command Usage

```bash
# In a Symfony app with the bundle registered:
php bin/console maxfield:plan <portal-file> [options]

Options:
  -n, --num-agents=1           Number of agents
      --num-iterations=1000    Candidate plans to generate
      --max-route-solutions=1000
      --max-route-runtime=60
  -o, --outdir=.               Output directory
      --output-csv             Also write CSV files
  -v                           Verbose output
```

## Standalone Usage (without Symfony)

```php
use Elkuku\MaxfieldBundle\Service\{
    AgentRouter, Fielder, Generator, GeometryService,
    LinkReorderer, MaxfieldPlanner, PlanOptimizer,
    PortalFileParser, ResultsGenerator
};

$planner = new MaxfieldPlanner(
    new PortalFileParser(),
    new PlanOptimizer(new Generator(new Fielder(), new LinkReorderer()), new GeometryService()),
    new AgentRouter(),
    new ResultsGenerator(),
    new LinkReorderer(),
);

$plan = $planner->run('portals.txt', numAgents: 2, outdir: './output', outputCsv: true, verbose: true);
```
