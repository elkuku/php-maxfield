<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Model\Assignment;
use Elkuku\MaxfieldBundle\Model\Plan;

/**
 * Generates text and CSV output files from a completed plan.
 * Mirrors results.py Results class (text output only; no images).
 */
class ResultsGenerator
{
    /**
     * Generate all output files in $outdir.
     * @param string $outdir Directory to write files into (created if absent)
     */
    public function generateAll(Plan $plan, string $outdir = '.', bool $outputCsv = false, bool $verbose = false): void
    {
        if (!is_dir($outdir)) {
            mkdir($outdir, 0777, true);
        }

        $this->keyPrep($plan, $outdir, $outputCsv, $verbose);
        $this->ownershipPrep($plan, $outdir, $verbose);
        $this->agentKeyPrep($plan, $outdir, $outputCsv, $verbose);
        $this->agentAssignments($plan, $outdir, $outputCsv, $verbose);
    }

    /** key_preparation.txt */
    private function keyPrep(Plan $plan, string $outdir, bool $outputCsv, bool $verbose): void
    {
        if ($verbose) {
            echo "Generating key preparation file.\n";
        }
        $fname = $outdir.'/key_preparation.txt';
        $lines = [];
        $lines[] = "Key Preparation: sorted by portal number\n";
        $lines[] = "Needed = total keys required";
        $lines[] = "Have = keys in inventory";
        $lines[] = "Remaining = keys necessary to farm";
        $lines[] = "# = portal number on portal map";
        $lines[] = "Name = portal name in portal file\n";
        $lines[] = "Needed ; Have ; Remaining ;   # ; Name";

        $csvLines = ['KeysNeeded, KeysHave, KeysRemaining, PortalNum, PortalName'];

        foreach ($plan->portals as $i => $portal) {
            $needed = $plan->graph->inDegree($i);
            $have = $portal->keys;
            $remaining = max(0, $needed - $have);
            $lines[] = sprintf('%6d ; %4d ; %9d ; %3d : %s', $needed, $have, $remaining, $i, $portal->name);
            $csvLines[] = sprintf('%d, %d, %d, %d, %s', $needed, $have, $remaining, $i, $portal->name);
        }

        file_put_contents($fname, implode("\n", $lines)."\n");
        if ($verbose) {
            echo "File saved to: $fname\n";
        }

        if ($outputCsv) {
            $csvFname = $outdir.'/key_preparation.csv';
            file_put_contents($csvFname, implode("\n", $csvLines)."\n");
            if ($verbose) {
                echo "CSV File saved to: $csvFname\n";
            }
        }
    }

    /** ownership_preparation.txt */
    private function ownershipPrep(Plan $plan, string $outdir, bool $verbose): void
    {
        if ($verbose) {
            echo "Generating ownership preparation file.\n";
        }
        $orderedLinks = $plan->graph->getOrderedLinks();
        $orderedOrigins = array_map(fn($l) => $l->origin, $orderedLinks);
        $orderedDests = array_map(fn($l) => $l->destination, $orderedLinks);

        $fname = $outdir.'/ownership_preparation.txt';
        $lines = [];
        $lines[] = "Ownership Preparation: sorted by portal number\n";
        $lines[] = "# = portal number on portal map";
        $lines[] = "Name = portal name in portal file\n";
        $lines[] = "These portals' first links are incoming. They should be at full resonators before linking.\n";
        $lines[] = "  # ; Name";

        foreach ($plan->portals as $i => $portal) {
            $inDest = array_search($i, $orderedDests);
            $inOrig = array_search($i, $orderedOrigins);
            if (($inDest !== false && $inOrig !== false && $inDest < $inOrig) ||
                ($inDest !== false && $inOrig === false)) {
                $lines[] = sprintf('%3d ; %s', $i, $portal->name);
            }
        }

        $lines[] = "\nThese portals' first links are outgoing. Their resonators can be applied when the first agent arrives.\n";
        $lines[] = "  # ; Name";

        foreach ($plan->portals as $i => $portal) {
            $inDest = array_search($i, $orderedDests);
            $inOrig = array_search($i, $orderedOrigins);
            if (($inOrig !== false && $inDest !== false && $inOrig < $inDest) ||
                ($inOrig !== false && $inDest === false)) {
                $lines[] = sprintf('%3d ; %s', $i, $portal->name);
            }
        }

        file_put_contents($fname, implode("\n", $lines)."\n");
        if ($verbose) {
            echo "File saved to: $fname\n";
        }
    }

    /** agent_key_preparation.txt */
    private function agentKeyPrep(Plan $plan, string $outdir, bool $outputCsv, bool $verbose): void
    {
        if ($verbose) {
            echo "Generating agent key preparation file.\n";
        }
        $fname = $outdir.'/agent_key_preparation.txt';
        $lines = [];
        $lines[] = "Agent Key Preparation: sorted by portal number\n";
        $lines[] = "Needed = keys this agent requires";
        $lines[] = "# = portal number on portal map";
        $lines[] = "Name = portal name in portal file\n";

        $csvLines = ['Agent, KeysNeeded, PortalNum, Portal Name'];

        for ($agent = 0; $agent < $plan->numAgents; $agent++) {
            $lines[] = sprintf('Keys for Agent %d', $agent + 1);
            $lines[] = "Needed ;   # ; Name";

            $destinations = array_map(
                fn(Assignment $a) => $a->link,
                array_filter($plan->assignments, fn(Assignment $a) => $a->agent === $agent)
            );

            foreach ($plan->portals as $i => $portal) {
                $count = \count(array_filter($destinations, fn($d) => $d === $i));
                if ($count > 0) {
                    $lines[] = sprintf('%6d ; %3d ; %s', $count, $i, $portal->name);
                    $csvLines[] = sprintf('%d, %d, %d, %s', $agent, $count, $i, $portal->name);
                }
            }
            $lines[] = '';
        }

        file_put_contents($fname, implode("\n", $lines)."\n");
        if ($verbose) {
            echo "File saved to: $fname\n";
        }

        if ($outputCsv) {
            $csvFname = $outdir.'/agent_key_preparation.csv';
            file_put_contents($csvFname, implode("\n", $csvLines)."\n");
            if ($verbose) {
                echo "CSV File saved to: $csvFname\n";
            }
        }
    }

    /** agent_assignments.txt + agent_N_assignment.txt */
    private function agentAssignments(Plan $plan, string $outdir, bool $outputCsv, bool $verbose): void
    {
        if ($verbose) {
            echo "Generating agent link assignments.\n";
        }

        $agentAssignments = array_fill(0, $plan->numAgents, []);

        $fname = $outdir.'/agent_assignments.txt';
        $lines = [];
        $lines[] = "Agent Linking Assignments: links should be made in this order\n";
        $lines[] = "Link = the current link number";
        $lines[] = "Agent = the person making this link";
        $lines[] = "# = portal number on portal map";
        $lines[] = "Link Origin/Destination = portal name in portal file\n";
        $lines[] = "Link ; Agent ;   # ; Link Origin";
        $lines[] = "                 # ; Link Destination\n";

        $csvLines = ['LinkNum, Agent, OriginNum, OriginName, DestinationNum, DestinationName'];

        // Group by arrival time
        $arrivals = array_unique(array_map(fn(Assignment $a) => $a->arrive, $plan->assignments));
        sort($arrivals);

        $linkNum = 1;
        foreach ($arrivals as $arrivalTime) {
            $group = array_filter($plan->assignments, fn(Assignment $a) => $a->arrive === $arrivalTime);
            foreach ($group as $ass) {
                $origin = $ass->location;
                $dest = $ass->link;
                $lines[] = sprintf('%4d ; %5d ; %3d ; %s', $linkNum, $ass->agent + 1, $origin, $plan->portals[$origin]->name);
                $lines[] = sprintf('             ; %3d : %s', $dest, $plan->portals[$dest]->name);
                $lines[] = '';
                $csvLines[] = sprintf('%d, %d, %d, %s, %d, %s', $linkNum, $ass->agent + 1, $origin, $plan->portals[$origin]->name, $dest, $plan->portals[$dest]->name);

                $agentAssignments[$ass->agent][] = [$linkNum, $origin, $plan->portals[$origin]->name, $dest, $plan->portals[$dest]->name];
                $linkNum++;
            }
        }

        file_put_contents($fname, implode("\n", $lines)."\n");
        if ($verbose) {
            echo "File saved to $fname\n";
        }

        if ($outputCsv) {
            $csvFname = $outdir.'/agent_assignments.csv';
            file_put_contents($csvFname, implode("\n", $csvLines)."\n");
            if ($verbose) {
                echo "CSV File saved to $csvFname\n";
            }
        }

        // Per-agent files
        foreach ($agentAssignments as $agentIdx => $asses) {
            if ($verbose) {
                echo sprintf("Generating link assignment for agent %d.\n", $agentIdx + 1);
            }
            $afname = $outdir.sprintf('/agent_%d_assignment.txt', $agentIdx + 1);
            $alines = [];
            $alines[] = sprintf("Agent %d Linking Assignment: links should be made in this order\n", $agentIdx + 1);
            $alines[] = "Link = the current link number";
            $alines[] = "# = portal number on portal map";
            $alines[] = "Link Origin/Destination = portal name in portal file\n";
            $alines[] = "Link ; Agent ;   # ; Link Origin";
            $alines[] = "                 # ; Link Destination\n";
            foreach ($asses as [$ln, $orig, $origName, $dest, $destName]) {
                $alines[] = sprintf('%4d ; %5d ; %3d ; %s', $ln, $agentIdx + 1, $orig, $origName);
                $alines[] = sprintf('             ; %3d : %s', $dest, $destName);
                $alines[] = '';
            }
            file_put_contents($afname, implode("\n", $alines)."\n");
            if ($verbose) {
                echo "File saved to $afname\n";
            }
        }

        if ($verbose) {
            echo "\n";
        }
    }
}
