# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Symfony Bundle implementing **Ingress Maxfield** — a strategy generator for the Ingress mobile game that produces optimal portal linking and fielding plans.

## Commands

```bash
# Install dependencies
composer install

# Run all unit tests
./vendor/bin/phpunit

# Run a single test file
./vendor/bin/phpunit tests/Service/GeometryServiceTest.php

# Run a single test method
./vendor/bin/phpunit --filter testConvexHullSquare

# Run integration test (requires composer autoload)
php integration_test.php

# Run the console command (when installed in a Symfony app)
bin/console maxfield:plan <portal-file> [--num-agents=1] [--num-iterations=1000] [--output-csv]
```

## Architecture

### Core Pipeline

`MaxfieldPlanner::run()` orchestrates the full pipeline:

1. **Parse** — `PortalFileParser` reads semicolon-delimited portal files into `Portal[]`
2. **Init geometry** — `PlanOptimizer::initPlan()` computes spherical distances, gnomonic projections, convex hull
3. **Optimize fields** — `PlanOptimizer::optimize()` runs N iterations of `Generator`, each producing a candidate via `Fielder` (recursive triangle subdivision); picks best by AP → path length → keys needed
4. **Route agents** — `AgentRouter` assigns links to agents with dependency enforcement
5. **Output** — `ResultsGenerator` writes key prep and assignment files (`.txt`/`.csv`)

### Key Data Model

- **`Plan`** — central container; holds `Portal[]`, `Graph`, geometry arrays, and `Assignment[]`
- **`Graph`** — directed edge container; enforces outgoing link limits (8 standard, 24 with SBUL); supports deep cloning per iteration
- **`FieldTriangle`** — recursive triangle that splits into sub-fields; calls `Graph::addLink()`

### Portal File Format

```
PortalName; IntelURL; [keys_count]; [SBUL]
```

Lines starting with `#` are comments. Duplicate coordinates are silently skipped.

### Bundle Registration

- `MaxfieldExtension` loads `config/services.yaml`
- All classes under `src/` except `Model/` and `MaxfieldBundle.php` are autowired as services
- `MaxfieldPlanCommand` is auto-tagged as a console command
