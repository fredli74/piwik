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
use Piwik\Tracker\RequestSet;
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

        $request = new RequestSet();
        $request->rememberEnvironment();

        while ($this->queue->shouldProcess()) {
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
