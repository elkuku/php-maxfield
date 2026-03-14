<?php

declare(strict_types=1);

// Standalone integration test script that runs the full Maxfield pipeline
// Usage: php integration_test.php

require __DIR__ . '/vendor/autoload.php';

use Elkuku\MaxfieldBundle\Service\AgentRouter;
use Elkuku\MaxfieldBundle\Service\Fielder;
use Elkuku\MaxfieldBundle\Service\Generator;
use Elkuku\MaxfieldBundle\Service\GeometryService;
use Elkuku\MaxfieldBundle\Service\LinkReorderer;
use Elkuku\MaxfieldBundle\Service\MaxfieldPlanner;
use Elkuku\MaxfieldBundle\Service\PlanOptimizer;
use Elkuku\MaxfieldBundle\Service\PortalFileParser;
use Elkuku\MaxfieldBundle\Service\ResultsGenerator;

$geo = new GeometryService();
$reorderer = new LinkReorderer();
$fielder = new Fielder();
$generator = new Generator($fielder, $reorderer);
$optimizer = new PlanOptimizer($generator, $geo);
$router = new AgentRouter();
$results = new ResultsGenerator();
$parser = new PortalFileParser();

$planner = new MaxfieldPlanner($parser, $optimizer, $router, $results, $reorderer);

$portalFile = '/home/elkuku/repos/maxfield/example/example_portals.txt';
$outdir = '/tmp/maxfield-php-out';

echo "Running Maxfield PHP integration test...\n\n";

$plan = $planner->run(
    filename: $portalFile,
    numAgents: 1,
    numFieldIterations: 50,  // reduced for speed in CI
    maxRouteSolutions: 100,
    maxRouteRuntime: 10,
    outdir: $outdir,
    outputCsv: true,
    verbose: true,
);

echo "\nIntegration test complete!\n";
echo "Output directory: $outdir\n";

// Verify files exist
$expectedFiles = [
    'key_preparation.txt',
    'key_preparation.csv',
    'ownership_preparation.txt',
    'agent_key_preparation.txt',
    'agent_key_preparation.csv',
    'agent_assignments.txt',
    'agent_assignments.csv',
    'agent_1_assignment.txt',
];

$allOk = true;
foreach ($expectedFiles as $file) {
    $path = $outdir.'/'.$file;
    if (file_exists($path)) {
        echo "  ✓ $file (".filesize($path)." bytes)\n";
    } else {
        echo "  ✗ MISSING: $file\n";
        $allOk = false;
    }
}

echo "\n";
echo $allOk ? "All output files generated successfully.\n" : "SOME FILES MISSING!\n";
exit($allOk ? 0 : 1);
