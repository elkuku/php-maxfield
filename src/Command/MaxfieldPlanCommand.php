<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Command;

use Elkuku\MaxfieldBundle\Service\MaxfieldPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'maxfield:plan',
    description: 'Ingress Maxfield: Generate a linking and fielding strategy from a portal list file.',
)]
class MaxfieldPlanCommand extends Command
{
    public function __construct(private readonly MaxfieldPlanner $planner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('filename', InputArgument::REQUIRED, 'The properly formatted portal file.')
            ->addOption('num-agents', null, InputOption::VALUE_REQUIRED, 'Number of agents in the operation.', 1)
            ->addOption('num-iterations', null, InputOption::VALUE_REQUIRED, 'Number of random field plans to generate before selecting the best.', 100)
            ->addOption('max-route-solutions', null, InputOption::VALUE_REQUIRED, 'Maximum number of agent routing solutions to generate.', 1000)
            ->addOption('max-route-runtime', null, InputOption::VALUE_REQUIRED, 'Maximum runtime of the agent routing algorithm in seconds.', 60)
            ->addOption('outdir', 'o', InputOption::VALUE_REQUIRED, 'Directory where results are saved.', '.')
            ->addOption('output-csv', null, InputOption::VALUE_NONE, 'Output machine-readable CSV files.')
            ->addOption('res-colors', 'r', InputOption::VALUE_NONE, 'Use Resistance color scheme (informational only).')
            ->addOption('skip-plots', null, InputOption::VALUE_NONE, 'Skip generating plots.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filename = (string) $input->getArgument('filename');
        $numAgents = (int) $input->getOption('num-agents');
        $numIterations = (int) $input->getOption('num-iterations');
        $maxRouteSolutions = (int) $input->getOption('max-route-solutions');
        $maxRouteRuntime = (int) $input->getOption('max-route-runtime');
        $outdir = (string) $input->getOption('outdir');
        $outputCsv = (bool) $input->getOption('output-csv');
        $resColors = (bool) $input->getOption('res-colors');
        $skipPlots = (bool) $input->getOption('skip-plots');
        $verbose = $output->isVerbose();

        if (!file_exists($filename)) {
            $io->error("Portal file not found: $filename");
            return Command::FAILURE;
        }

        try {
            $this->planner->run(
                filename: $filename,
                numAgents: $numAgents,
                numFieldIterations: $numIterations,
                maxRouteSolutions: $maxRouteSolutions,
                maxRouteRuntime: $maxRouteRuntime,
                outdir: $outdir,
                outputCsv: $outputCsv,
                resColors: $resColors,
                skipPlots: $skipPlots,
                verbose: $verbose,
            );
        } catch (\InvalidArgumentException $e) {
            $io->error('Portal file error: '.$e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Unexpected error: '.$e->getMessage());
            if ($output->isDebug()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        $io->success("Plan complete. Results saved to: $outdir");

        return Command::SUCCESS;
    }
}
