<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Tracker\RequestSet;
use Piwik\Tracker\TrackerConfig;
use Piwik\Tracker;
use Piwik\Translate;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;

class Queue
{
    /**
     * @var Redis
     */
    private $backend;

    private $key = 'trackingQueueV1';
    private $numRequestsToProcessAtSameTime = 50;

    public function __construct()
    {
        $this->backend = new Redis();
    }

    public function setNumberOfRequestsToProcessAtSameTime($numRequests)
    {
        $this->numRequestsToProcessAtSameTime = $numRequests;
    }

    public function addRequestSet(RequestSet $requests)
    {
        if (!$requests->hasRequests()) {
            return;
        }

        $value = $requests->getState();
        $value = json_encode($value);

        $this->backend->appendValuesToList($this->key, array($value));
    }

    public function shouldProcess()
    {
        $numRequests = $this->backend->getNumValuesInList($this->key);

        return $numRequests >= $this->numRequestsToProcessAtSameTime;
    }

    /**
     * @return RequestSet[]
     */
    public function getRequestSetsToProcess()
    {
        $values = $this->backend->getFirstXValuesFromList($this->key, $this->numRequestsToProcessAtSameTime);

        $requests = array();
        foreach ($values as $value) {
            $params = json_decode($value, true);

            $request = new RequestSet();
            $request->restoreState($params);
            $requests[] = $request;
        }

        return $requests;
    }

    public function markRequestSetsAsProcessed()
    {
        $this->backend->removeFirstXValuesFromList($this->key, $this->numRequestsToProcessAtSameTime);
    }

    public function isEnabled()
    {
        $enabled = TrackerConfig::getConfigValue('queue_enabled');

        if ($enabled) {
            $this->backend->checkIsInstalled(); // todo this should not really be done here
        }

        return (bool) $enabled;
    }
}
