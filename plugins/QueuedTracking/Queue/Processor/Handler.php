<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue\Processor;

use Piwik\Common;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Tracker\RequestSet;
use Exception;
use Piwik\Url;
use RedisException;

class Handler
{
    private $transaction;

    private $hasError = false;

    private $requestSetsToRetry = array();
    private $count = 0;

    private $numTrackedRequestsBeginning = 0;

    public function init(Tracker $tracker)
    {
        $this->transaction = $this->getDb()->beginTransaction();
        $this->hasError = false;
        $this->numTrackedRequestsBeginning = $tracker->getCountOfLoggedRequests();
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        $requestSet->restoreEnvironment();

        $this->count = 0;

        //try {
        foreach ($requestSet->getRequests() as $request) {
            $tracker->trackRequest($request);
            $this->count++;
        }

        $this->requestSetsToRetry[] = $requestSet;
        // } catch (Tracker\Db\DbException $e) {
            // TODO this is a db issue and not a request set issue, we should stop processing and retry later? or just ignore all?
            // we could sleep for a tiny bit and hoping it works soonish again?

        // } catch (RedisException $e) {
            // TODO this is a redis issue and not a request set issue, we should stop processing and retry later? or just ignore all?
            // see DbException

        // }
    }

    private function getDb()
    {
        return Tracker::getDatabase();
    }

    public function onException(Tracker $tracker, RequestSet $requestSet, Exception $e)
    {
        // todo
        $this->hasError = true;

        $tracker->setCountOfLoggedRequests($this->numTrackedRequestsBeginning);

        if ($this->count > 0) {
            // remove the first one that failed and all following (standard bulk tracking behavior)
            $insertedRequests = array_slice($requestSet->getRequests(), 0, $this->count);
            $requestSet->setRequests($insertedRequests);
            $this->requestSetsToRetry[] = $requestSet;
        }
    }

    public function finish(Tracker $tracker)
    {
        if ($this->hasError) {
            $this->getDb()->rollBack($this->transaction);
            return $this->requestSetsToRetry;
        }

        $this->getDb()->commit($this->transaction);
        return array();
    }

}
