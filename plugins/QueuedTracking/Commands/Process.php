<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Access;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Tracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Process extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:process');
        $this->setDescription('Processes all queued tracking requests in case there are enough requests in the queue and in case they are not already in process by another script.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Access::getInstance()->setSuperUserAccess(false);
        Tracker::loadTrackerEnvironment();

        $backend   = Queue\Factory::makeBackend();
        $queue     = Queue\Factory::makeQueue($backend);
        $processor = new Processor($queue, $backend);

        $numRequestsQueued = $queue->getNumberOfRequestSetsInQueue();

        if (!$queue->shouldProcess()) {
            $numRequestsNeeded = $queue->getNumberOfRequestsToProcessAtSameTime();
            $this->writeSuccessMessage($output, array("Nothing to process. Only $numRequestsQueued request sets are queued, $numRequestsNeeded are needed to start processing the queue."));
        } elseif (!$processor->acquireLock()) {
            $this->writeSuccessMessage($output, array("Nothing to proccess. $numRequestsQueued request sets are queued and they are already in process by another script."));
        } else {
            $output->writeln("<info>Starting to process $numRequestsQueued request sets, this can take a while</info>");

            $this->setProgressCallback($processor, $output, $numRequestsQueued);

            try {
                $processor->process();
                $processor->unlock();
            } catch (\Exception $e) {
                $processor->unlock();

                throw $e;
            }

            $this->writeSuccessMessage($output, array('Queue processed'));
        }
    }

    private function setProgressCallback(Processor $processor, OutputInterface $output, $numRequests)
    {
        $processor->setOnProcessNewSetOfRequestsCallback(function (Queue $queue) use ($output, $numRequests) {
            $output->write("\x0D");
            $output->write($queue->getNumberOfRequestSetsInQueue() . ' left in queue      ');
        });

    }
}
