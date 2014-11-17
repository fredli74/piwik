<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tracker\Queue;

use Piwik\Tracker;
use Piwik\Tracker\Queue;
use Piwik\Tracker\Queue\Backend\Redis;

/**
 * This class represents a page view, tracking URL, page title and generation time.
 *
 */
class Processor
{
    /**
     * @var Queue
     */
    private $queue;

    /**
     * @var Redis
     */
    private $backend;

    private $lockKey = 'trackingQueueLock';

    public function __construct($queue)
    {
        $this->queue   = $queue;
        $this->backend = new Redis();
    }

    public function shouldProcess()
    {
        return $this->queue->shouldProcess() && $this->acquireLock();
    }

    public function process(Tracker $tracker)
    {
        $response = new Response();
        $response->init();

        do {
            $this->expireLock($ttlInSeconds = 120);

            $queuedRequests = $this->queue->getRequestsToProcess();

            $requests = new Tracker\Requests();
            $requests->setRequests($queuedRequests);
            $tracker->main($requests, $response);

            $this->queue->markRequestsAsProcessed();

        } while ($this->queue->shouldProcess());

        $response->send();
    }

    public function finishProcess()
    {
        $this->unlock();
    }

    private function acquireLock()
    {
        return $this->backend->setIfNotExists($this->lockKey, 1);
    }

    private function unlock()
    {
        $this->backend->delete($this->lockKey);
    }

    private function expireLock($ttlInSeconds)
    {
        return $this->backend->expire($this->lockKey, $ttlInSeconds);
    }

}
