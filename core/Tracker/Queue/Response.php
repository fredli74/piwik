<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Tracker\Queue;

use Piwik\Log;
use Piwik\Tracker;
use Piwik\Tracker\Response as TrackerResponse;

class Response extends TrackerResponse
{
    public function init(Tracker $tracker)
    {
        Log::debug('Queue init');

        $this->sendResponseToBrowserDirectly();

        ob_start();
    }

    public function send()
    {
        Log::debug('Queue end');

        return ob_get_clean();
    }

    public function outputException(Tracker $tracker, $e, $statusCode)
    {
        Log::debug('Occurred exception: ' . $e->getMessage() . ' with status code ' . $statusCode);
    }

    public function outputResponse(Tracker $tracker)
    {
        Log::debug('Number of logged requests:' . $tracker->getCountOfLoggedRequests());
    }

    private function sendResponseToBrowserDirectly()
    {
        header("Connection: close\r\n", true);
        header("Content-Encoding: none\r\n", true);
        header('Content-Length: ' . ob_get_length(), true);
        ob_end_flush();
        ob_flush();
        flush();
    }

}
