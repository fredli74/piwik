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
    private $numRequestsToProcessAtSameTime = 50;

    public function __construct()
    {
        $this->backend = new Redis();
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

        $this->backend->appendValuesToList($this->key, $values);
    }

    public function shouldProcess()
    {
        $numRequests = $this->backend->getNumValuesInList($this->key);

        return $numRequests >= $this->numRequestsToProcessAtSameTime;
    }

    public function getRequestsToProcess()
    {
        $values = $this->backend->getFirstXValuesFromList($this->key, $this->numRequestsToProcessAtSameTime);

        $requests = array();
        foreach ($values as $value) {
            $requests[] = json_decode($value);
        }

        return $requests;
    }

    public function markRequestsAsProcessed()
    {
        $this->backend->removeFirstXValuesFromList($this->key, $this->numRequestsToProcessAtSameTime);
    }

    public function isEnabled()
    {
        $enabled = TrackerConfig::getConfigValue('queue_enabled');

        if ($enabled) {
            $this->backend->checkIsInstalled();
        }

        return $enabled;
    }
}
