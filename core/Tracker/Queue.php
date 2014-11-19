<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Tracker;

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

    public function setNumberOfRequestsToProcessAtSameTime($numRequests)
    {
        $this->numRequestsToProcessAtSameTime = $numRequests;
    }

    public function addRequests($requests)
    {
        if (empty($requests)) {
            return;
        }

        $values = array();
        /** @var Request $request */
        foreach ($requests as $request) {
            // $request->setUserIsAuthenticated();

            $params = $request->getParams();
            /*
            $params['cdt']    = $request->getCurrentTimestamp();
            $params['idsite'] = $request->getIdSite();
            $params['ua']     = $request->getUserAgent();
            $params['cip']    = $request->getIpString();
*/
            $values[] = json_encode($params);
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
            $params  = json_decode($value, true);
            $request = new Request($params);
         //   $request->setUserIsAuthenticated();
            $requests[] = $request;
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
            $this->backend->checkIsInstalled(); // todo this should not really be done here
        }

        return (bool) $enabled;
    }
}
