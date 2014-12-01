<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue;

use Piwik\Common;
use Piwik\Tracker;
use Piwik\Tracker\RequestSet;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;

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
    private $lockValue;

    private $callbackOnProcessNewSet;

    public function __construct(Queue $queue, Backend $backend)
    {
        $this->queue   = $queue;
        $this->backend = $backend;
    }

    public function process()
    {
        $tracker = new Tracker();

        if (!$tracker->shouldRecordStatistics()) {
            return $tracker;
        }

        $request = new RequestSet();
        $request->rememberEnvironment();

        while ($this->queue->shouldProcess()) {

            if ($this->callbackOnProcessNewSet) {
                call_user_func($this->callbackOnProcessNewSet, $this->queue);
            }

            $numTrackedRequests = $tracker->getCountOfLoggedRequests();

            $queuedRequestSets = $this->queue->getRequestSetsToProcess();
            $validRequestSets  = $this->processRequestSets($tracker, $queuedRequestSets);

            if ($this->needsARetry($queuedRequestSets, $validRequestSets)) {
                // try once more without the failed ones
                $tracker->setCountOfLoggedRequests($numTrackedRequests);
                $this->processRequestSets($tracker, $validRequestSets);
            }

            $this->queue->markRequestSetsAsProcessed();
            // in case of DB Exception maybe not mark them as processed and stop
            // queue. (Also Log an error which could then once we use Monolog trigger an email or so)
        }

        $request->restoreEnvironment();

        return $tracker;
    }

    /**
     * @param \Callable $callback
     */
    public function setOnProcessNewSetOfRequestsCallback($callback)
    {
        $this->callbackOnProcessNewSet = $callback;
    }

    /**
     * @param  RequestSet[] $queuedRequestSets
     * @param  RequestSet[] $validRequestSets
     * @return boolean
     */
    private function needsARetry($queuedRequestSets, $validRequestSets)
    {
        if (count($queuedRequestSets) != count($validRequestSets)) {
            return true;
        }

        foreach ($queuedRequestSets as $index => $request) {

            $numQueued    = count($request->getRequests());
            $numProcessed = count($validRequestSets[$index]->getRequests());

            if ($numQueued !== $numProcessed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Tracker $tracker
     * @param RequestSet[] $queuedRequestSets
     * @return mixed
     */
    private function processRequestSets(Tracker $tracker, $queuedRequestSets)
    {
        $this->expireLock($ttlInSeconds = 300); // todo this processor does not really know it was locked by another class so should not really expire it.

        $validRequestSets = array();

        $transaction = $this->getDb()->beginTransaction();
        $hasError = false;

        foreach ($queuedRequestSets as $index => $requestSet) {
            $requestSet->restoreEnvironment();

            $count = 0;

            try {
                foreach ($requestSet->getRequests() as $request) {
                    $tracker->trackRequest($request);
                    $count++;
                }
                $validRequestSets[] = $requestSet;
            } catch (\Exception $e) {
                // TODO
                // TODO also handle db exception maybe differently
                $hasError = true;

                if ($count > 0) {
                    // remove the first one that failed and all following (standard bulk tracking behavior)
                    $insertedRequests = array_slice($requestSet->getRequests(), 0, $count);
                    $requestSet->setRequests($insertedRequests);
                    $validRequestSets[] = $requestSet;
                }

            }
        }

        if ($hasError) {
            $this->getDb()->rollBack($transaction);
        } else {
            $this->getDb()->commit($transaction);
        }

        return $validRequestSets;
    }

    private function getDb()
    {
        return Tracker::getDatabase();
    }

    public function acquireLock()
    {
        if (!$this->lockValue) {
            $this->lockValue = Common::generateUniqId();
        }

        return $this->backend->setIfNotExists($this->lockKey, $this->lockValue);
    }

    public function unlock()
    {
        $this->backend->deleteIfKeyHasValue($this->lockKey, $this->lockValue);
    }

    private function expireLock($ttlInSeconds)
    {
        $this->backend->expire($this->lockKey, $ttlInSeconds);
    }

}
