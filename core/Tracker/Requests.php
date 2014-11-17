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
use Piwik\Config;
use Piwik\Plugins\SitesManager\SiteUrls;
use Piwik\Url;

class Requests
{

    /**
     * The set of visits to track.
     *
     * @var array
     */
    private $requests = null;

    /**
     * The token auth supplied with a bulk visits POST.
     *
     * @var string
     */
    private $tokenAuth = null;

    /**
     * Whether we're currently using bulk tracking or not.
     *
     * @var bool
     */
    private $usingBulkTracking = false;

    public function setRequests($requests)
    {
        $this->requests = $requests;
    }

    public function isUsingBulkRequest()
    {
        return is_array($this->requests) && count($this->requests) > 1;
    }

    public function getRequests()
    {
        if (!is_null($this->requests)) {
            return $this->requests;
        }

        try {
            $this->initRequests(null);

        } catch (Exception $ex) {
            Common::printDebug('Failed to init requests: ' . $ex->getMessage());
        }

        return $this->requests;
    }

    public function getTokenAuth()
    {
        if (!is_null($this->tokenAuth)) {
            return $this->tokenAuth;
        }

        return Common::getRequestVar('token_auth', false);
    }

    protected function initRequests()
    {
        $rawData = $this->getRawBulkRequest();

        if (!empty($rawData)) {
            $this->usingBulkTracking = strpos($rawData, '"requests"') || strpos($rawData, "'requests'");

            if ($this->usingBulkTracking) {
                $this->initBulkTrackingRequests($rawData);
                $this->authenticateBulkTrackingRequests();
                return;
            }
        }

        // Not using bulk tracking
        $this->requests = (!empty($_GET) || !empty($_POST)) ? array($_GET + $_POST) : array();
    }

    private function isBulkTrackingRequireTokenAuth()
    {
        return !empty(Config::getInstance()->Tracker['bulk_requests_require_authentication']);
    }

    private function initBulkTrackingRequests($rawData)
    {
        list($this->requests, $this->tokenAuth) = $this->getRequestsArrayFromBulkRequest($rawData);

        if (!empty($this->requests)) {
            foreach ($this->requests as $index => $request) {
                // if a string is sent, we assume its a URL and try to parse it
                if (is_string($request)) {
                    $params = array();

                    $url = @parse_url($request);
                    if (!empty($url)) {
                        @parse_str($url['query'], $params);
                        $request = $params;
                    }
                }

                $this->requests[$index] = new Request($request, $this->tokenAuth);
            }
        }
    }

    public function getRequestsArrayFromBulkRequest($rawData)
    {
        $rawData = trim($rawData);
        $rawData = Common::sanitizeLineBreaks($rawData);

        // POST data can be array of string URLs or array of arrays w/ visit info
        $jsonData = json_decode($rawData, $assoc = true);

        $tokenAuth = Common::getRequestVar('token_auth', false, 'string', $jsonData);

        $requests = array();
        if (isset($jsonData['requests'])) {
            $requests = $jsonData['requests'];
        }

        return array($requests, $tokenAuth);
    }

    private function authenticateBulkTrackingRequests()
    {
        if ($this->isBulkTrackingRequireTokenAuth()) {
            if (empty($this->tokenAuth)) {
                throw new Exception("token_auth must be specified when using Bulk Tracking Import. "
                    . " See <a href='http://developer.piwik.org/api-reference/tracking-api'>Tracking Doc</a>");
            }

            if (!empty($this->requests)) {
                foreach ($this->requests as $request) {
                    if (!$request->isAuthenticated()) {
                        throw new Exception(sprintf("token_auth specified does not have Admin permission for idsite=%s", $requestObj->getIdSite()));
                    }
                }
            }
        }
    }

    public function hasRequests()
    {
        return !empty($this->requests);
    }

    /**
     * @return string
     */
    public function getRawBulkRequest()
    {
        return file_get_contents("php://input");
    }

    public function getRedirectUrl()
    {
        return Common::getRequestVar('redirecturl', false, 'string');
    }

    public function hasRedirectUrl()
    {
        $redirectUrl = $this->getRedirectUrl();

        return !empty($redirectUrl);
    }

    public function getAllSiteIdsWithinRequest()
    {
        if (empty($this->requests)) {
            return array();
        }

        $siteIds = array();

        foreach ($this->requests as $request) {
            $siteIds[] = (int) $request['idsite'];
        }

        return array_unique($siteIds);
    }

}
