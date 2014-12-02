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
     * @var Handler
     */
    private $handler;

    /**
     * @var Redis
     */
    private $backend;

    private $lockKey = 'trackingQueueLock';
    private $lockValue;

    private $callbackOnProcessNewSet;

    public function __construct(Backend $backend)
    {
        $this->backend = $backend;
        $this->handler = new Handler();
    }

    public function process(Queue $queue)
    {
        $tracker = new Tracker();

        if (!$tracker->shouldRecordStatistics()) {
            return $tracker;
        }

        $request = new RequestSet();
        $request->rememberEnvironment();

        while ($queue->shouldProcess() && $this->hasLock()) {
            if ($this->callbackOnProcessNewSet) {
                call_user_func($this->callbackOnProcessNewSet, $queue, $tracker);
            }

            $queuedRequestSets = $queue->getRequestSetsToProcess();

            if (!empty($queuedRequestSets)) {
                $queue->markRequestSetsAsProcessed();

                $requestSetsToRetry = $this->processRequestSets($tracker, $queuedRequestSets);
                $this->processRequestSets($tracker, $requestSetsToRetry);
            }
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
     * @param RequestSet[] $queuedRequestSets
     * @return mixed
     */
    private function processRequestSets(Tracker $tracker, $queuedRequestSets)
    {
        if (empty($queuedRequestSets)) {
            return array();
        }

        $this->handler->init($tracker);

        foreach ($queuedRequestSets as $index => $requestSet) {
            $ttl = $this->getTtlForProcessingRequestSet($requestSet);
            $this->expireLock($ttl);

            try {
                $this->handler->process($tracker, $requestSet);
            } catch (\Exception $e) {
                Common::printDebug($e->getMessage());
                $this->handler->onException($tracker, $requestSet, $e);
            }
        }

        $this->expireLock(120); // gives us 120 seconds to finish processing

        $requestSetsToRetry = $this->handler->finish($tracker);

        return $requestSetsToRetry;
    }

    private function getTtlForProcessingRequestSet(RequestSet $requestSet)
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

        $locked = $this->backend->setIfNotExists($this->lockKey, $this->lockValue, $ttlInSeconds = 60);

        return $locked;
    }

    private function hasLock()
    {
        return !empty($this->lockValue) && $this->lockValue === $this->backend->get($this->lockKey);
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
