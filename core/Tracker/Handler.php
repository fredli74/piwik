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
    public function init(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
        $tracker->init();

        // maybe belongs to response?
        $redirectUrl = $requests->shouldPerformRedirectToUrl();

        if (!empty($redirectUrl)) {
            Url::redirectToUrlNoExit($redirectUrl);
        }

        $response->init($tracker);
    }

    public function process(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
        foreach ($requests->getRequests() as $request) {
            $tracker->trackRequest($request, $requests->getTokenAuth());
        }
    }

    public function onStartTrackRequests(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
    }

    public function onAllRequestsTracked(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
        $tasks = new ScheduledTasksRunner();
        if ($tasks->shouldRun($tracker)) {
            $tasks->runScheduledTasks();
        }
    }

    public function onException(Tracker $tracker, Tracker\Response $response, Exception $e)
    {
        Common::printDebug("Exception: " . $e->getMessage());

        $statusCode = 500;
        if ($e instanceof UnexpectedWebsiteFoundException) {
            $statusCode = 400;
        } elseif ($e instanceof InvalidRequestParameterException) {
            $statusCode = 400;
        }

        $tracker->disconnectDatabase();
        $response->outputException($tracker, $e, $statusCode);

        die(1);
        exit;
    }

    public function finish(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
        Piwik::postEvent('Tracker.end');

        $tracker->disconnectDatabase();

        $response->outputResponse($tracker);
        $response->send();
    }


}
