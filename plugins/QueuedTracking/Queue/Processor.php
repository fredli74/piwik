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
use Piwik\Plugins\QueuedTracking\Queue\Processor\Handler;
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
        $handler = new Handler();
        $request->rememberEnvironment();

        while ($this->queue->shouldProcess() && $this->hasLock()) {
            // gives us 10 sec to start processing which should only take a few ms...
            $this->expireLock(10);

            if ($this->callbackOnProcessNewSet) {
                call_user_func($this->callbackOnProcessNewSet, $this->queue, $tracker);
            }

            $queuedRequestSets  = $this->queue->getRequestSetsToProcess();
            $requestSetsToRetry = $this->processRequestSets($tracker, $handler, $queuedRequestSets);
            $this->processRequestSets($tracker, $handler, $requestSetsToRetry);

            $this->queue->markRequestSetsAsProcessed();
        }

        $request->restoreEnvironment();

        return $tracker;
    }

    /**
     * @param \Callable $callback
     */
    public function setOnProcessNewRequestSetCallback($callback)
    {
        $this->callbackOnProcessNewSet = $callback;
    }

    /**
     * @param Tracker $tracker
     * @param Handler $handler
     * @param RequestSet[] $queuedRequestSets
     * @return mixed
     */
    private function processRequestSets(Tracker $tracker, Handler $handler, $queuedRequestSets)
    {
        if (empty($queuedRequestSets)) {
            return array();
        }

        $handler->init($tracker);

        foreach ($queuedRequestSets as $index => $requestSet) {
            $ttl = $this->getTtlToExpireWhenProcessingARequestSet($requestSet);
            $this->expireLock($ttl);

            try {
                $handler->process($tracker, $requestSet);
            } catch (\Exception $e) {
                Common::printDebug($e->getMessage());
                $handler->onException($tracker, $requestSet, $e);
            }
        }

        $this->expireLock(10); // gives us 10 seconds to finish processing

        $requestSetsToRetry = $handler->finish($tracker);

        return $requestSetsToRetry;
    }

    private function getTtlToExpireWhenProcessingARequestSet(RequestSet $requestSet)
    {
        $ttl = $requestSet->getNumberOfRequests() * 2;
         // 2 seconds per request set should give it enough time to process it
        return $ttl;
    }

    public function acquireLock()
    {
        if (!$this->lockValue) {
            $this->lockValue = Common::generateUniqId();
        }

        return $this->backend->setIfNotExists($this->lockKey, $this->lockValue);
    }

    private function hasLock()
    {
        return $this->lockValue === $this->backend->get($this->lockKey);
    }

    public function unlock()
    {
        $this->backend->deleteIfKeyHasValue($this->lockKey, $this->lockValue);
        $this->lockValue = null;
    }

    private function expireLock($ttlInSeconds)
    {
        if ($ttlInSeconds > 0) {
            $this->backend->expire($this->lockKey, $ttlInSeconds);
        }

    }

}
