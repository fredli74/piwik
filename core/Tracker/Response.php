<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Tracker;

use Exception;
use Piwik\Common;
use Piwik\Profiler;
use Piwik\Tracker;

/**
 * Class used by the logging script piwik.php called by the javascript tag.
 * Handles the visitor & his/her actions on the website, saves the data in the DB,
 * saves information in the cookie, etc.
 *
 * We try to include as little files as possible (no dependency on 3rd party modules).
 *
 */
class Response
{
    protected function outputAccessControlHeaders()
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($requestMethod !== 'GET') {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
            Common::sendHeader('Access-Control-Allow-Origin: ' . $origin);
            Common::sendHeader('Access-Control-Allow-Credentials: true');
        }
    }

    public function init()
    {
        ob_start();
    }

    public function send()
    {
        ob_end_flush();
    }

    private function getOutputBuffer()
    {
        return ob_get_contents();
    }

    /**
     * Echos an error message & other information, then exits.
     *
     * @param Exception $e
     * @param bool $authenticated
     * @param int  $statusCode eg 500
     */
    public function outputException(Tracker $tracker, $e, $statusCode)
    {
        Common::sendResponseCode($statusCode);
        error_log(sprintf("Error in Piwik (tracker): %s", str_replace("\n", " ", $this->getMessageFromException($e))));

        if ($tracker->isDebugModeEnabled()) {
            Common::sendHeader('Content-Type: text/html; charset=utf-8');
            $trailer = '<span style="color: #888888">Backtrace:<br /><pre>' . $e->getTraceAsString() . '</pre></span>';
            $headerPage = file_get_contents(PIWIK_INCLUDE_PATH . '/plugins/Morpheus/templates/simpleLayoutHeader.tpl');
            $footerPage = file_get_contents(PIWIK_INCLUDE_PATH . '/plugins/Morpheus/templates/simpleLayoutFooter.tpl');
            $headerPage = str_replace('{$HTML_TITLE}', 'Piwik &rsaquo; Error', $headerPage);

            echo $headerPage . '<p>' . $this->getMessageFromException($e) . '</p>' . $trailer . $footerPage;
        } else {
            $this->sendResponse($tracker);
        }

        die(1);
        exit;
    }

    /**
     * Cleanup
     */
    public function outputResponse(Tracker $tracker)
    {
        switch ($tracker->getState()) {
            case Tracker::STATE_LOGGING_DISABLE:
                $this->sendResponse($tracker);
                Common::printDebug("Logging disabled, display transparent logo");
                break;

            case Tracker::STATE_EMPTY_REQUEST:
                Common::printDebug("Empty request => Piwik page");
                echo "<a href='/'>Piwik</a> is a free/libre web <a href='http://piwik.org'>analytics</a> that lets you keep control of your data.";
                break;

            case Tracker::STATE_NOSCRIPT_REQUEST:
            case Tracker::STATE_NOTHING_TO_NOTICE:
            default:
                $this->sendResponse($tracker);
                Common::printDebug("Nothing to notice => default behaviour");
                break;
        }

        Common::printDebug("End of the page.");

        if ($tracker->isDebugModeEnabled() && $tracker->isDatabaseConnected()) {
            $db = Tracker::getDatabase();
            $db->recordProfiling();
            Profiler::displayDbTrackerProfile($db);
        }
    }

    private function sendResponse(Tracker $tracker)
    {
        if ($tracker->isDebugModeEnabled()) {
            return;
        }

        if (strlen($this->getOutputBuffer()) > 0) {
            // If there was an error during tracker, return so errors can be flushed
            return;
        }

        $this->outputAccessControlHeaders();

        $request = $_GET + $_POST;

        if (array_key_exists('send_image', $request) && $request['send_image'] === '0') {
            Common::sendResponseCode(204);

            return;
        }

        $this->outputTransparentGif();
    }

    private function outputTransparentGif ()
    {
        $transGifBase64 = "R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==";
        Common::sendHeader('Content-Type: image/gif');

        print(base64_decode($transGifBase64));
    }

    /**
     * Gets the error message to output when a tracking request fails.
     *
     * @param Exception $e
     * @return string
     */
    protected function getMessageFromException($e)
    {
        // Note: duplicated from FormDatabaseSetup.isAccessDenied
        // Avoid leaking the username/db name when access denied
        if ($e->getCode() == 1044 || $e->getCode() == 42000) {
            return "Error while connecting to the Piwik database - please check your credentials in config/config.ini.php file";
        }

        if (Common::isPhpCliMode()) {
            return $e->getMessage() . "\n" . $e->getTraceAsString();
        }

        return $e->getMessage();
    }

}
