<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue\Processor;

use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Tracker\RequestSet;
use Exception;
use Piwik\Url;

class Handler
{
    protected $transactionId;
    protected $hasError = false;

    private $requestSetsToRetry = array();
    private $count = 0;
    private $numTrackedRequestsBeginning = 0;

    public function init(Tracker $tracker)
    {
        $this->requestSetsToRetry = array();
        $this->hasError = false;
        $this->numTrackedRequestsBeginning = $tracker->getCountOfLoggedRequests();
        $this->transactionId = $this->getDb()->beginTransaction();
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        $requestSet->restoreEnvironment();

        $this->count = 0;

        foreach ($requestSet->getRequests() as $request) {
            $tracker->trackRequest($request);
            $this->count++;
        }

        $this->requestSetsToRetry[] = $requestSet;
    }

    public function onException(RequestSet $requestSet, Exception $e)
    {
        // todo: how do we want to handle DbException or RedisException?

        $this->forceARollback();

        if ($this->count > 0) {
            // remove the first one that failed and all following (standard bulk tracking behavior)
            $insertedRequests = array_slice($requestSet->getRequests(), 0, $this->count);
            $requestSet->setRequests($insertedRequests);
            $this->requestSetsToRetry[] = $requestSet;
        }
    }

    public function forceARollback()
    {
        $this->hasError = true;
    }

    /**
     * @param Tracker $tracker
     * @return RequestSet[]
     */
    public function finish(Tracker $tracker)
    {
        if ($this->hasError) {
            $tracker->setCountOfLoggedRequests($this->numTrackedRequestsBeginning);

            $this->getDb()->rollBack($this->transactionId);
            return $this->requestSetsToRetry;
        }

        $this->getDb()->commit($this->transactionId);
        return array();
    }

    protected function getDb()
    {
        return Tracker::getDatabase();
    }

}
