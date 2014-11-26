<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
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
        $queue     = new Queue();
        $processor = new Processor($queue);

        $numRequests = $queue->getNumberOfRequestSetsInQueue();

        if (!$queue->shouldProcess()) {
            $this->writeSuccessMessage($output, array("Nothing to process. Only $numRequests request sets are queued."));
        } elseif (!$processor->acquireLock()) {
            $this->writeSuccessMessage($output, array("Nothing to proccess. $numRequests request sets are queued and they are already in process by another script."));
        } else {
            $output->writeln("<info>Starting to process $numRequests request sets, this can take a while</info>");

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
}
