<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Tracker;

use Piwik\Config;
use Piwik\Tracker;
use Piwik\Translate;
use Piwik\Tracker\Queue\Backend\Redis;

class Queue
{
    /**
     * @var Redis
     */
    private $backend;
    private $key = 'trackingQueueV1';
    private $lockKey = 'trackingQueueLock';
    private $numRequestsToProcessAtSameTime = 3;

    public function __construct()
    {
        $this->backend = new Redis();
    }

    public function lock($ttlInSeconds)
    {
        $this->backend->save($this->lockKey, 1, $ttlInSeconds);
    }

    public function unlock()
    {
        $this->backend->delete($this->lockKey);
    }

    public function isLocked()
    {
        return $this->backend->exists($this->lockKey);
    }

    public function popRequests($requests, $server)
    {
        if (empty($requests)) {
            return;
        }

        $values = array();
        foreach ($requests as $request) {
            if ($request instanceof Request) {
                $request = $request->getParams();
            }

            $values[] = json_encode($request);
        }

        $this->backend->popValues($this->key, $values);
    }

    public function shouldProcess()
    {
        $numRequests = $this->backend->getNumValues($this->key);

        return $numRequests >= $this->numRequestsToProcessAtSameTime;
    }

    public function shiftRequests()
    {
        $values = $this->backend->shiftValues($this->key, $this->numRequestsToProcessAtSameTime);

        $requests = array();
        foreach ($values as $value) {
            $requests[] = json_decode($value);
        }

        return $requests;
    }

    public function isEnabled()
    {
        $trackerConfig = Config::getInstance()->Tracker;

        $enabled = $trackerConfig['queue_enabled'];

        if ($enabled) {
            $this->backend->checkIsInstalled();
        }

        return $enabled;
    }
}
