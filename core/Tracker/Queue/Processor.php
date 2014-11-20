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
            $numTrackedRequests = $tracker->getCountOfLoggedRequests();

            $queuedRequests    = $this->queue->getRequestsToProcess();
            $processedRequests = $this->processRequests($tracker, $queuedRequests);

            if ($this->needsARetry($queuedRequests, $processedRequests)) {
                // try once more without the failed ones
                $tracker->setCountOfLoggedRequests($numTrackedRequests);
                $this->processRequests($tracker, $processedRequests);
            }

            $this->queue->markRequestsAsProcessed();
            // in case of DB Exception maybe not mark them as processed and stop
            // queue. (Also Log an error which could then once we use Monolog trigger an email or so)
        }

        $requests->restoreEnvironment();

        return $tracker;
    }

    /**
     * @param Requests[] $queuedRequests
     * @param Requests[] $processedRequests
     * @return boolean
     */
    private function needsARetry($queuedRequests, $processedRequests)
    {
        if (count($queuedRequests) != count($processedRequests)) {
            return true;
        }

        foreach ($queuedRequests as $index => $request) {

            $numQueued    = count($request->getRequests());
            $numProcessed = count($processedRequests[$index]->getRequests());

            if ($numQueued !== $numProcessed) {
                return true;
            }
        }

        return false;
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
        $hasError = false;

        foreach ($queuedRequests as $index => $requests) {
            $requests->restoreEnvironment();

            $count = 0;

            try {
                foreach ($requests->getRequests() as $request) {
                    $tracker->trackRequest($request);
                    $count++;
                }
                $processedRequests[] = $requests;
            } catch (\Exception $e) {
                // TODO
                // TODO also handle db exception maybe differently
                $hasError = true;

                if ($count > 0) {
                    // remove the first one that failed and all following (standard bulk tracking behavior)
                    $insertedRequests = array_slice($requests->getRequests(), 0, $count);
                    $requests->setRequests($insertedRequests);
                    $processedRequests[] = $requests;
                }

            }
        }

        if ($hasError) {
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
