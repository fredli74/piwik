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

    public function init(Tracker $tracker, Tracker\Requests $requests)
    {
        $tracker->init();

        // maybe belongs to response?
        $redirectUrl = $requests->shouldPerformRedirectToUrl();

        if (!empty($redirectUrl)) {
            Url::redirectToUrlNoExit($redirectUrl);
        }

        $this->response->init($tracker);
    }

    public function process(Tracker $tracker, Tracker\Requests $requests)
    {
        foreach ($requests->getRequests() as $request) {
            $tracker->trackRequest($request, $requests->getTokenAuth());
        }
    }

    public function onStartTrackRequests(Tracker $tracker, Tracker\Requests $requests)
    {
    }

    public function onAllRequestsTracked(Tracker $tracker, Tracker\Requests $requests)
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

    public function finish(Tracker $tracker, Tracker\Requests $requests)
    {
        Piwik::postEvent('Tracker.end');

        $tracker->disconnectDatabase();

        $this->response->outputResponse($tracker);
        $this->response->send();
    }


}
