<?php

declare(strict_types=1);

namespace Internal\CustomCommand\Command;

use Internal\CustomCommand\Services\StateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

final class SiteStateCommand extends Command
{
    public function __construct(
        private StateService $stateService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }


    
    /**
     * Main method for creating and executing a recursive cte in order to get the page tree
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Make sure the _cli_ user is loaded
        Bootstrap::initializeBackendAuthentication();
        $io = new SymfonyStyle($input, $output);

        // ____Production part____
        $this->stateService->cte();   // Call of the main method = create the page tree with recursive cte



        // ____Performance test part____
        // Performance testing parameters
        $times=1;           // Number of executions (to get a more consitent value)
        $pageRepo=true;     // Set to false, to disable performance test for PageRepository
        $pageTreeView=true; // Set to false, to disable performance test for PageTreeView
        $rCte=true;         // Set to false, to disable performance test for recursive cte

        $this->performanceTests($times, $pageRepo, $pageTreeView, $rCte); // Call of the performance tests



        // ____Demo part____
        // Only for demo purposes - not relevant for recursive cte
        // $this->stateService->sqlInjectionDemo();
        // $this->stateService->querybuilderParameterDemo();

        return Command::SUCCESS;
    }


    /**
     * Executes performancetests for PageRepository->getPageIdsRecursive(), PageTreeView->getTree() and StateService->buildRcte()
     * and prints the results on the terminal
     */
    protected function performanceTests(int $times=1, bool $pageRepo=true, bool $pageTreeView=true, bool $rCte=true): void
    {

        $parentId = 0;              // Page uid to fetch the page tree for, needed for PageTreeView->getTree()
        // $parentIds = [1, 19];    // Page uid's of my testenvironment
        $parentIds = [2, 1, 3, 5601, 284, 4458, 281, 13807, 19698, 16265, 24381, 35860, 37968, 38182, 51212, 58760];  // Page uid's to fetch the page tree for, needed for PageRepository->getPageIdsRecursive()
        $fields = ['uid', 'title']; // Fields which are fetched by buildRcte()  (at least 1 needed)
        $metaFields = true;         // Get the metafields for buildRcte()  (needed when $output=true)
        $workspace = 0;             // Get the page tree for workspace X
        $output = false;            // Prints the page tree on the terminal


        for ($i=1; $i<=$times; $i++) {

            if ($pageRepo) {
                    echo "PageRepo ($i): " . ($this->stateService->perfTestPageRepo($parentIds, $output)) / 1000000 . "\n";      // Milliseconds
            }

            if ($pageTreeView) {
                    echo "PageTreeView ($i): " . ($this->stateService->perfTestPageTreeView($parentId, $output)) / 1000000 . "\n";      // Milliseconds;
            }

            if ($rCte) {
                    echo "rCTE ($i): " . ($this->stateService->perfTestRcte($fields, $workspace, $metaFields, $output)) / 1000000 . "\n";      // Milliseconds;;
            }

        }
    }

}