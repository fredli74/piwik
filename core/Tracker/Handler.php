<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tracker;

use Piwik\Common;
use Piwik\Exception\InvalidRequestParameterException;
use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Piwik;
use Piwik\Tracker;
use Exception;
use Piwik\Url;

// TODO create interfaces for Handler and Response
class Handler
{
    /**
     * @var Response
     */
    private $response;

    public function __construct()
    {
        $this->setResponse(new Response());
    }

    public function setResponse($response)
    {
        $this->response = $response;
    }

    public function init(Tracker $tracker, RequestSet $requestSet)
    {
        $tracker->init();

        $this->response->init($tracker);
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        foreach ($requestSet->getRequests() as $request) {
            $tracker->trackRequest($request, $requestSet->getTokenAuth());
        }
    }

    public function onStartTrackRequests(Tracker $tracker, RequestSet $requestSet)
    {
    }

    public function onAllRequestsTracked(Tracker $tracker, RequestSet $requestSet)
    {
        $tasks = new ScheduledTasksRunner();
        if ($tasks->shouldRun($tracker)) {
            $tasks->runScheduledTasks();
        }
    }

    public function onException(Tracker $tracker, Exception $e)
    {
        Common::printDebug("Exception: " . $e->getMessage());

        $statusCode = 500;
        if ($e instanceof UnexpectedWebsiteFoundException) {
            $statusCode = 400;
        } elseif ($e instanceof InvalidRequestParameterException) {
            $statusCode = 400;
        }

        $tracker->disconnectDatabase();
        $this->response->outputException($tracker, $e, $statusCode);

        die(1);
        exit;
    }

    public function finish(Tracker $tracker, RequestSet $requestSet)
    {
        Piwik::postEvent('Tracker.end');

        $tracker->disconnectDatabase();

        $this->sendResponse($tracker, $requestSet);
    }

    /**
     * @param Tracker $tracker
     * @param Tracker\RequestSet $requestSet
     */
    protected function sendResponse(Tracker $tracker, RequestSet $requestSet)
    {
        $redirectUrl = $requestSet->shouldPerformRedirectToUrl();

        if (!empty($redirectUrl)) {
            Url::redirectToUrl($redirectUrl);
        }

        $this->response->outputResponse($tracker);
        $this->response->send();
    }


}
