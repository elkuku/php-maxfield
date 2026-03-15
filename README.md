# Maxfield Bundle

Symfony Bundle implementing [Ingress Maxfield](https://github.com/tvwenger/maxfield) — generates optimal portal linking and fielding plans for the Ingress mobile game.

## Installation

### Via Packagist (once published)

```bash
composer require elkuku/maxfield-bundle
```

### From a local path

Add to your Symfony app's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "/path/to/maxfield-php-2" }
]
```

```bash
composer require elkuku/maxfield-bundle:@dev
```

### From GitHub (not yet on Packagist)

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/elkuku/maxfield-php-2" }
]
```

```bash
composer require elkuku/maxfield-bundle:dev-master
```

### Register the bundle

Add to `config/bundles.php` (Symfony Flex does this automatically if a recipe is present):

```php
return [
    // ...
    Elkuku\MaxfieldBundle\MaxfieldBundle::class => ['all' => true],
];
```

No further configuration is required. All services are autowired.

---

## Portal File Format

Create a plain text file with one portal per line:

```
# Lines starting with # are comments and are ignored

PortalName; IntelURL; [keys]; [SBUL]
```

| Field | Required | Description |
|-------|----------|-------------|
| `PortalName` | Yes | Any name (no `;` or `#` characters) |
| `IntelURL` | Yes | Intel map URL containing `pll=lat,lon` |
| `keys` | No | Number of keys already in inventory (integer) |
| `SBUL` | No | Mark portal as having a Softbank Ultra Link (raises outgoing link limit from 8 to 24) |

**Example:**

```
# My operation portals
Town Hall; https://intel.ingress.com/intel?pll=38.032646,-78.477578; 3
Library; https://intel.ingress.com/intel?pll=38.032570,-78.477450
Post Office; https://intel.ingress.com/intel?pll=38.033100,-78.476900; 0; SBUL
```

- Portals with duplicate coordinates are silently skipped.
- A minimum of 3 portals is required to create any fields.

---

## Usage

### Console command

```bash
bin/console maxfield:plan <portal-file> [options]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--num-agents` / `-n` | `1` | Number of agents participating in the operation |
| `--num-iterations` | `1000` | Candidate field plans to generate; higher = better plan, slower |
| `--max-route-solutions` | `1000` | Max agent routing solutions to evaluate (multi-agent only) |
| `--max-route-runtime` | `60` | Max seconds for agent routing (multi-agent only) |
| `--outdir` / `-o` | `.` | Directory to write output files into (created if absent) |
| `--output-csv` | off | Also write machine-readable CSV files |
| `--res-colors` / `-r` | off | Use Resistance color scheme (informational only) |

**Examples:**

```bash
# Single agent, defaults
bin/console maxfield:plan portals.txt

# Three agents, save to a specific directory, include CSV
bin/console maxfield:plan portals.txt --num-agents=3 --outdir=./output --output-csv

# Verbose output with more iterations for better quality
bin/console maxfield:plan portals.txt --num-iterations=5000 -v
```

### PHP API

Inject `MaxfieldPlanner` and call `run()`:

```php
use Elkuku\MaxfieldBundle\Service\MaxfieldPlanner;

class MyController
{
    public function __construct(private readonly MaxfieldPlanner $planner) {}

    public function generate(): void
    {
        $plan = $this->planner->run(
            filename: '/path/to/portals.txt',
            numAgents: 2,
            numFieldIterations: 1000,
            maxRouteSolutions: 1000,
            maxRouteRuntime: 60,
            outdir: '/tmp/my-operation',
            outputCsv: true,
            verbose: false,
        );

        // $plan->graph->ap        — total AP
        // $plan->graph->numLinks  — number of links
        // $plan->graph->numFields — number of fields
        // $plan->assignments      — array of Assignment objects
    }
}
```

`run()` returns the completed `Plan` object and also writes all output files to `$outdir`.

---

## Output Files

All files are written to the directory specified by `--outdir`.

| File | Description |
|------|-------------|
| `key_preparation.txt` | Keys needed, in inventory, and still to farm — per portal |
| `key_preparation.csv` | Same data in CSV format (`--output-csv` only) |
| `ownership_preparation.txt` | Which portals need full resonators before linking begins |
| `agent_key_preparation.txt` | Keys each individual agent needs to carry |
| `agent_key_preparation.csv` | Same data in CSV format (`--output-csv` only) |
| `agent_assignments.txt` | Full link order for all agents combined |
| `agent_assignments.csv` | Same data in CSV format (`--output-csv` only) |
| `agent_N_assignment.txt` | Individual assignment sheet for agent N |

### Reading the assignment files

Links must be made **in the listed order**. Each entry shows:

```
Link ; Agent ;   # ; Link Origin name
                 # ; Link Destination name
```

- **Link** — global sequence number
- **Agent** — which agent makes this link (1-based)
- **#** — portal number on the portal map