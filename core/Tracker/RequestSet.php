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
use Piwik\Piwik;
use Piwik\Plugins\SitesManager\SiteUrls;
use Piwik\Url;

class RequestSet
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

    private $env = array();

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
    }

    public function getNumberOfRequests()
    {
        if (is_array($this->requests)) {
            return count($this->requests);
        }

        return 0;
    }

    public function getRequests()
    {
        if (!$this->areRequestsInitialized()) {
            return array();
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

    private function areRequestsInitialized()
    {
        return !is_null($this->requests);
    }

    public function initRequestsAndTokenAuth()
    {
        if ($this->areRequestsInitialized()) {
            return;
        }

        Piwik::postEvent('Tracker.initRequestSet', array($this));

        if (!$this->areRequestsInitialized()) {
            $this->requests = array();

            if (!empty($_GET) || !empty($_POST)) {
                $this->setRequests(array($_GET + $_POST));
            }
        }
    }

    public function hasRequests()
    {
        return !empty($this->requests);
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

    public function getState()
    {
        $requests = array(
            'requests'  => array(),
            'env'       => $this->getEnvironment(),
            'tokenAuth' => $this->getTokenAuth(),
            'time'      => time()
        );

        foreach ($this->getRequests() as $request) {
            $requests['requests'][] = $request->getParams();
            // todo we need to add cdt (timestamp) but we will need permission to restore later! etc
        }

        return $requests;
    }

    public function restoreState($state)
    {
        $this->setTokenAuth($state['tokenAuth']);
        $this->setRequests($state['requests']);
        $this->setEnvironment($state['env']);

        foreach ($this->getRequests() as $request) {
            $request->setCurrentTimestamp($state['time']);
        }
    }

    public function rememberEnvironment()
    {
        $this->setEnvironment($this->getEnvironment());
    }

    private function setEnvironment($env)
    {
        $this->env = $env;
    }

    public function getEnvironment()
    {
        $this->env = array(
            'server' => $_SERVER
        );

        return $this->env;
    }

    public function restoreEnvironment()
    {
        if (empty($this->env)) {
            return;
        }

        $_SERVER = $this->env['server'];
    }


}
