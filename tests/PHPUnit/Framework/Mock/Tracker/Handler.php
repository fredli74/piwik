<?php
/**
* Piwik - free/libre analytics platform
*
* @link http://piwik.org
* @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
*/

namespace Piwik\Tests\Framework\Mock\Tracker;

use Exception;
use Piwik\Tracker;
use Piwik\Tracker\RequestSet;

class Handler extends \Piwik\Tracker\Handler
{
    public $isInit = false;
    public $isInitTrackingRequests = false;
    public $isOnStartTrackRequests = false;
    public $isProcessed = false;
    public $isOnAllRequestsTracked = false;
    public $isOnException = false;
    public $isFinished = false;

    private $doTriggerExceptionInProcess = false;


    public function init(Tracker $tracker, RequestSet $requestSet)
    {
        $this->isInit = true;
    }

    public function enableTriggerExceptionInProcess()
    {
        $this->doTriggerExceptionInProcess = true;
    }

    public function initTrackingRequests(Tracker $tracker, RequestSet $requestSet)
    {
        $this->isInitTrackingRequests = true;
    }

    public function onStartTrackRequests(Tracker $tracker, RequestSet $requestSet)
    {
        $this->isOnStartTrackRequests = true;
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        if ($this->doTriggerExceptionInProcess) {
            throw new Exception;
        }

        $this->isProcessed = true;
    }

    public function onAllRequestsTracked(Tracker $tracker, RequestSet $requestSet)
    {
        $this->isOnAllRequestsTracked = true;
    }

    public function onException(Tracker $tracker, RequestSet $requestSet, Exception $e)
    {
        $this->isOnException = true;
    }

    public function finish(Tracker $tracker, RequestSet $requestSet)
    {
        $this->isFinished = true;
    }

}
