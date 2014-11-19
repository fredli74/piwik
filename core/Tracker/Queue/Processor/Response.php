<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Tracker\Queue\Processor;

use Piwik\Common;
use Piwik\Tracker;
use Piwik\Tracker\Response as TrackerResponse;

class Response extends TrackerResponse
{
    public function init(Tracker $tracker)
    {
        Common::printDebug('Queue processor init');
    }

    public function send()
    {
        Common::printDebug('Queue processor send response');
    }

    public function outputException(Tracker $tracker, $e, $statusCode)
    {
    }

    public function outputResponse(Tracker $tracker)
    {
        Common::printDebug('Processed ' . $tracker->getCountOfLoggedRequests() . ' requests');
    }

}
