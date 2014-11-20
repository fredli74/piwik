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
use Piwik\Tracker\Handler;
use Piwik\Tracker\Requests;
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

    public function process()
    {
        $tracker = new Tracker();

        if (!$tracker->shouldRecordStatistics()) {
            return $tracker;
        }

        $requests = new Requests();
        $requests->rememberEnvironment();

        while ($this->queue->shouldProcess()) {
            $queuedRequests    = $this->queue->getRequestsToProcess();
            $processedRequests = $this->processRequests($tracker, $queuedRequests);

            if (count($queuedRequests) > count($processedRequests)) {
                // try once more without the failed ones
                $this->processRequests($tracker, $processedRequests);
            }

            $this->queue->markRequestsAsProcessed();
        }

        $requests->restoreEnvironment();

        return $tracker;
    }

    /**
     * @param Tracker $tracker
     * @param Requests[] $queuedRequests
     * @return mixed
     */
    private function processRequests(Tracker $tracker, $queuedRequests)
    {
        $this->expireLock($ttlInSeconds = 180); // todo this processor does not really now it was locked by another class so should not really expire it.

        $processedRequests = array();

        $transaction = $this->getDb()->beginTransaction();

        foreach ($queuedRequests as $index => $requests) {
            $requests->restoreEnvironment();

            try {
                foreach ($requests->getRequests() as $request) {
                    $tracker->trackRequest($request);
                }
                $processedRequests[] = $requests;
            } catch (\Exception $e) {
                // TODO
            }
        }

        if (count($processedRequests) < count($queuedRequests)) {
            $this->getDb()->rollBack($transaction);
        } else {
            $this->getDb()->commit($transaction);
        }

        return $processedRequests;
    }

    private function getDb()
    {
        return Tracker::getDatabase();
    }

    public function acquireLock()
    {
        return $this->backend->setIfNotExists($this->lockKey, 1);
    }

    public function unlock()
    {
        $this->backend->delete($this->lockKey);
    }

    private function expireLock($ttlInSeconds)
    {
        return $this->backend->expire($this->lockKey, $ttlInSeconds);
    }

}
