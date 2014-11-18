<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Tracker\BulkTracking;

use Exception;
use Piwik\Common;
use Piwik\Tracker;

/**
 * Class used by the logging script piwik.php called by the javascript tag.
 * Handles the visitor & his/her actions on the website, saves the data in the DB,
 * saves information in the cookie, etc.
 *
 * We try to include as little files as possible (no dependency on 3rd party modules).
 *
 */
class Response extends Tracker\Response
{
    /**
     * Echos an error message & other information, then exits.
     *
     * @param Tracker $tracker
     * @param Exception $e
     * @param int  $statusCode eg 500
     */
    public function outputException(Tracker $tracker, $e, $statusCode)
    {
        Common::sendResponseCode($statusCode);
        error_log(sprintf("Error in Piwik (tracker): %s", str_replace("\n", " ", $this->getMessageFromException($e))));

        // when doing bulk tracking we return JSON so the caller will know how many succeeded
        $result = array(
            'status'  => 'error',
            'tracked' => $tracker->getCountOfLoggedRequests()
        );

        // send error when in debug mode
        if ($tracker->isDebugModeEnabled()) {
            $result['message'] = $this->getMessageFromException($e);
        }

        Common::sendHeader('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Cleanup
     */
    public function outputResponse(Tracker $tracker)
    {
        $result = array(
            'status'  => 'success',
            'tracked' => $tracker->getCountOfLoggedRequests()
        );

        $this->outputAccessControlHeaders();

        Common::sendHeader('Content-Type: application/json');
        echo json_encode($result);
    }

}
