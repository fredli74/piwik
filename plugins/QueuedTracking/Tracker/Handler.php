<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Tracker;

use Piwik\Common;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Tracker\RequestSet;
use Exception;
use Piwik\Url;

/**
 * @method Response getResponse()
 */
class Handler extends Tracker\Handler
{
    public function __construct()
    {
        $this->setResponse(new Response());
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        $backend = Queue\Factory::makeBackend();
        $queue   = Queue\Factory::makeQueue($backend);

        $queue->addRequestSet($requestSet);
        $tracker->setCountOfLoggedRequests($requestSet->getNumberOfRequests());

        Common::printDebug('Added requests to queue');

        $this->redirectIfNeeded($requestSet);
        $this->getResponse()->outputResponse($tracker);
        $this->getResponse()->sendResponseToBrowserDirectly();

        $settings = Queue\Factory::getSettings();
        if ($settings->processDuringTrackingRequest->getValue()) {
            $this->processQueueIfNeeded($queue, $backend);
        }
    }

    private function processQueueIfNeeded(Queue $queue, Queue\Backend\Redis $redis)
    {
        if ($queue->shouldProcess()) {
            $this->processIfNotLocked(new Processor($queue, $redis));
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
