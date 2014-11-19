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
use Piwik\Plugins\SitesManager\SiteUrls;
use Piwik\Url;

class Requests
{

    /**
     * The set of visits to track.
     *
     * @var Request[]
     */
    private $requests = null;

    /**
     * The token auth supplied with a bulk visits POST.
     *
     * @var string
     */
    private $tokenAuth = false;

    /**
     * Whether we're currently using bulk tracking or not.
     *
     * @var bool
     */
    private $usingBulkTracking = false;

    public function setRequests($requests)
    {
        $this->requests = array();

        foreach ($requests as $request) {

            if (empty($request)) {
                continue;
            }

            if (!$request instanceof Request) {
                $request = new Request($request, $this->getTokenAuth());
            }

            $this->requests[] = $request;
        }
    }

    public function setTokenAuth($tokenAuth)
    {
        $this->tokenAuth = $tokenAuth;
        // TODO update the tokenAuth of all Request instances? in case setRequest is called before setTokenAuth
    }

    public function isUsingBulkRequest()
    {
        return $this->getNumberOfRequests() > 1;
    }

    public function getNumberOfRequests()
    {
        $requests = $this->getRequests();

        if (is_array($requests)) {
            return count($requests);
        }

        return 0;
    }

    public function getRequests()
    {
        if (!is_null($this->requests)) {
            return $this->requests;
        }

        try {
            $this->initRequests();

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
        $this->setRequests(array());

        $rawData = $this->getRawBulkRequest();

        if (!empty($rawData)) {
            $this->usingBulkTracking = strpos($rawData, '"requests"') || strpos($rawData, "'requests'");

            if ($this->usingBulkTracking) {
                $this->initBulkTrackingRequests($rawData);
                $this->authenticateBulkTrackingRequests();
                return;
            }
        }

        if (!empty($_GET) || !empty($_POST)) {
            $this->setRequests(array($_GET + $_POST));
        }
    }

    private function isBulkTrackingRequireTokenAuth()
    {
        $requiresAuth = TrackerConfig::getConfigValue('bulk_requests_require_authentication');

        return !empty($requiresAuth);
    }

    private function initBulkTrackingRequests($rawData)
    {
        list($requests, $tokenAuth) = $this->getRequestsArrayFromBulkRequest($rawData);

        $this->setTokenAuth($tokenAuth);

        if (!empty($requests)) {
            $validRequests = array();

            foreach ($requests as $index => $request) {
                // if a string is sent, we assume its a URL and try to parse it
                if (is_string($request)) {
                    $params = array();

                    $url = @parse_url($request);
                    if (!empty($url)) {
                        @parse_str($url['query'], $params);
                        $validRequests[] = $params;
                    }
                } else {
                    $validRequests[] = $request;
                }
            }

            $this->setRequests($validRequests);
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

    private function checkTokenAuthNotEmpty()
    {
        $token = $this->getTokenAuth();

        if (empty($token)) {
            throw new Exception("token_auth must be specified when using Bulk Tracking Import. "
                . " See <a href='http://developer.piwik.org/api-reference/tracking-api'>Tracking Doc</a>");
        }
    }

    private function authenticateBulkTrackingRequests()
    {
        if ($this->isBulkTrackingRequireTokenAuth()) {

            $this->checkTokenAuthNotEmpty();

            foreach ($this->getRequests() as $request) {
                if (!$request->isAuthenticated()) {
                    throw new Exception(sprintf("token_auth specified does not have Admin permission for idsite=%s", $requestObj->getIdSite()));
                }
            }
        }
    }

    public function hasRequests()
    {
        $requests = $this->getRequests();

        return !empty($requests);
    }

    /**
     * @return string
     */
    public function getRawBulkRequest()
    {
        return file_get_contents("php://input");
    }

    private function getRedirectUrl()
    {
        return Common::getRequestVar('redirecturl', false, 'string');
    }

    private function hasRedirectUrl()
    {
        $redirectUrl = $this->getRedirectUrl();

        return !empty($redirectUrl);
    }

    private function getAllSiteIdsWithinRequest()
    {
        if (empty($this->requests)) {
            return array();
        }

        $siteIds = array();
        foreach ($this->requests as $request) {
            $siteIds[] = (int) $request->getIdSite();
        }

        return array_unique($siteIds);
    }

    // TODO maybe move to reponse? or somewhere else? not sure where!
    public function shouldPerformRedirectToUrl()
    {
        if (!$this->hasRedirectUrl()) {
            return false;
        }

        if (!$this->hasRequests()) {
            return false;
        }

        $redirectUrl = $this->getRedirectUrl();
        $host        = Url::getHostFromUrl($redirectUrl);

        if (empty($host)) {
            return false;
        }

        $urls     = new SiteUrls();
        $siteUrls = $urls->getAllCachedSiteUrls();
        $siteIds  = $this->getAllSiteIdsWithinRequest();

        foreach ($siteIds as $siteId) {
            if (empty($siteUrls[$siteId])) {
                continue;
            }

            if (Url::isHostInUrls($host, $siteUrls[$siteId])) {
                return $redirectUrl;
            }
        }

        return false;
    }

}
