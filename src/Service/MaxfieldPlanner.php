<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Model\Plan;

/**
 * High-level facade that orchestrates the full Maxfield planning pipeline.
 * Mirrors maxfield.py maxfield() function.
 */
class MaxfieldPlanner
{
    public function __construct(
        private readonly PortalFileParser $parser,
        private readonly PlanOptimizer $optimizer,
        private readonly AgentRouter $router,
        private readonly ResultsGenerator $results,
        private readonly LinkReorderer $reorderer,
        private readonly ImageGenerator $imageGenerator,
    ) {
    }

    /**
     * Run the complete Maxfield pipeline from a portal file.
     *
     * @param string $filename           Path to portal file
     * @param int    $numAgents          Number of agents
     * @param int    $numFieldIterations Candidate field plans to generate
     * @param int    $maxRouteSolutions  Max routing solutions (multi-agent)
     * @param int    $maxRouteRuntime    Max routing runtime in seconds
     * @param string $outdir             Output directory
     * @param bool   $outputCsv         Also write CSV files
     * @param bool   $verbose            Print progress information
     */
    public function run(
        string $filename,
        int $numAgents = 1,
        int $numFieldIterations = 100,
        int $maxRouteSolutions = 1000,
        int $maxRouteRuntime = 60,
        string $outdir = '.',
        bool $outputCsv = false,
        bool $resColors = false,
        bool $skipPlots = false,
        bool $verbose = false,
    ): Plan {
        $start = microtime(true);

        // 1. Parse portal file
        $portals = $this->parser->parseFile($filename);
        if ($verbose) {
            printf("Found %d portals in portal file: %s\n\n", \count($portals), $filename);
        }

        // 2. Create plan
        $plan = new Plan($portals, $numAgents);

        // 3. Initialise geometry
        $this->optimizer->initPlan($plan);

        // 4. Optimise field plan
        if ($verbose) {
            printf("Starting field generation (%d iterations).\n", $numFieldIterations);
            $t = microtime(true);
        }
        $this->optimizer->optimize($plan, $numFieldIterations, $verbose);
        if ($verbose) {
            printf("Field generation runtime: %.1f seconds.\n\n", microtime(true) - $t);
        }

        // Print plan summary
        $this->printSummary($plan);

        // 5. Route agents
        if ($verbose) {
            echo "Optimizing agent link assignments.\n";
            $t = microtime(true);
        }
        $plan->assignments = $this->router->routeAgents(
            $plan->graph,
            $plan->portalsDists,
            $numAgents,
            $maxRouteSolutions,
            $maxRouteRuntime,
        );
        if ($verbose) {
            printf("Route optimization runtime: %.1f seconds\n\n", microtime(true) - $t);
        }

        // 6. Update link order from assignments and reset dependencies
        foreach ($plan->assignments as $i => $ass) {
            $plan->graph->getLink($ass->location, $ass->link)->order = $i;
        }
        $this->reorderer->reset($plan->graph);

        // 7. Generate output files
        $this->results->generateAll($plan, $outdir, $outputCsv, $verbose);

        if (!$skipPlots) {
            $this->imageGenerator->generateAll($plan, $outdir, $resColors, $verbose);
        }

        if ($verbose) {
            $lastDepart = max(array_map(fn($a) => $a->depart, $plan->assignments));
            printf("Total plan build time: %.1f minutes\n\n", $lastDepart / 60.0);
            printf("Total maxfield runtime: %.1f seconds\n", microtime(true) - $start);
        }

        return $plan;
    }

    private function printSummary(Plan $plan): void
    {
        echo "==============================\n";
        echo "Maxfield Plan Results:\n";
        printf("    portals         = %d\n", \count($plan->portals));
        printf("    links           = %d\n", $plan->graph->numLinks);
        printf("    fields          = %d\n", $plan->graph->numFields);
        printf("    max keys needed = %d\n", $plan->graph->maxKeys);
        printf("    AP from portals = %d\n", $plan->graph->apPortals);
        printf("    AP from links   = %d\n", $plan->graph->apLinks);
        printf("    AP from fields  = %d\n", $plan->graph->apFields);
        printf("    TOTAL AP        = %d\n", $plan->graph->ap);
        echo "==============================\n\n";
    }
}
