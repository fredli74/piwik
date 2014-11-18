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

class Handler extends Tracker\Handler
{
    /**
     * @var Queue
     */
    private $queue;

    public function __construct($queue)
    {
        $this->queue = $queue;
    }

    public function process(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
        $this->queue->popRequests($requests->getRequests(), $_SERVER);

        Common::printDebug('Added requests to queue');

        $this->processQueueIfPossible();

        return false;
    }

    private function processQueueIfPossible()
    {
        $processor = new Processor($this->queue);

        if ($this->queue->shouldProcess() && $processor->acquireLock()) {

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
