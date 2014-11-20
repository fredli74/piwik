<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tracker\Queue;

use Piwik\Common;
use Piwik\Tracker;
use Piwik\Tracker\Queue;
use Exception;
use Piwik\Url;

class Handler extends Tracker\Handler
{
    public function __construct()
    {
        $this->setResponse(new Response());
    }

    public function process(Tracker $tracker, Tracker\Requests $requests)
    {
        $queue = new Queue();
        $queue->addRequest($requests);
        $tracker->setCountOfLoggedRequests($requests->getNumberOfRequests());

        Common::printDebug('Added requests to queue');

        $this->sendResponse($tracker, $requests);
        $this->processQueueIfNeeded($queue);
    }

    private function processQueueIfNeeded(Queue $queue)
    {
        if ($queue->shouldProcess()) {
            $this->processIfNotLocked(new Processor($queue));
        }
    }

    private function processIfNotLocked(Processor $processor)
    {
        if ($processor->acquireLock()) {

            Common::printDebug('We are going to process the queue');

            try {
                $processor->process();
            } catch (Exception $e) {
                Common::printDebug('Failed to process queue: ' . $e->getMessage());
                // TODO how could we report errors better as the response is already sent? also monitoring ...
            }

            $processor->unlock();
        }
    }


}
