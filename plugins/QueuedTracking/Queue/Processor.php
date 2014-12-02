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
use Exception;

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

                $requestSetsToRetry = $this->processRequestSets($tracker, $queuedRequestSets);
                $this->processRequestSets($tracker, $requestSetsToRetry);
                $queue->markRequestSetsAsProcessed();
            }
        }

        $request->restoreEnvironment();

        return $tracker;
    }

    /**
     * @param  Tracker $tracker
     * @param  RequestSet[] $queuedRequestSets
     * @return RequestSet[]
     * @throws Exception
     */
    private function processRequestSets(Tracker $tracker, $queuedRequestSets)
    {
        if (empty($queuedRequestSets)) {
            return array();
        }

        $this->handler->init($tracker);

        foreach ($queuedRequestSets as $index => $requestSet) {
            $this->expireLockToMakeSureWeHaveLockLongEnoughForProcessingRequestSet($requestSet);

            try {
                $this->handler->process($tracker, $requestSet);
            } catch (\Exception $e) {
                Common::printDebug('Failed to process a queued request set' . $e->getMessage());
                $this->handler->onException($requestSet, $e);
            }
        }

        if ($this->hasLock()) {
            $this->expireLockToMakeSureWeHaveLockLongEnoughToFinishQueuedRequests($queuedRequestSets);
        } else {
            // force a rollback in finish, too risky another process is processing the same bunch of request sets
            $this->handler->rollBack($tracker);

            throw new Exception('Stopped processing queue as we no longer have lock');
        }

        if ($this->handler->hasErrors()) {
            $this->handler->rollBack($tracker);
        } else {
            $this->handler->commit();
        }

        return $this->handler->getRequestSetsToRetry();
    }

    /**
     * @param \Callable $callback
     */
    public function setOnProcessNewRequestSetCallback($callback)
    {
        $this->callbackOnProcessNewSet = $callback;
    }

    private function expireLockToMakeSureWeHaveLockLongEnoughToFinishQueuedRequests($queuedRequests)
    {
        $ttl = count($queuedRequests) * 2;
        // in case there are 50 queued requests it gives us 100 seconds to commit/rollback and to start new batch

        $ttl = max($ttl, 20); // lock at least for 20 seconds

        $this->expireLock($ttl);
    }

    private function expireLockToMakeSureWeHaveLockLongEnoughForProcessingRequestSet(RequestSet $requestSet)
    {
        // 2 seconds per request set should give it enough time to process it
        $ttl = $requestSet->getNumberOfRequests() * 2;
        $ttl = max($ttl, 4); // lock for at least 4 seconds

        $this->expireLock($ttl);
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
