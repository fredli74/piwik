<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Tracker\Queue;

use Piwik\Common;
use Piwik\Tracker;
use Piwik\Tracker\Response as TrackerResponse;

class Response extends TrackerResponse
{
    public function init(Tracker $tracker)
    {
        Common::printDebug('Queue init');

        ob_start();
    }

    public function send()
    {
        Common::printDebug('Queue send response');

        $this->sendResponseToBrowserDirectly();
    }

    private function sendResponseToBrowserDirectly()
    {
        while (ob_get_level() > 1) {
            ob_end_flush();
        }

        Common::sendHeader("Connection: close\r\n", true);
        Common::sendHeader("Content-Encoding: none\r\n", true);
        Common::sendHeader('Content-Length: ' . ob_get_length(), true);
        ob_end_flush();
        ob_flush();
        flush();
    }

}
