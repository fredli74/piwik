<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Exception;
use Piwik\Exception\InvalidRequestParameterException;
use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Plugins\PrivacyManager\Config as PrivacyManagerConfig;
use Piwik\Plugins\SitesManager\SiteUrls;
use Piwik\Tracker\Db as TrackerDb;
use Piwik\Tracker\Db\DbException;
use Piwik\Tracker\Request;
use Piwik\Tracker\Requests;
use Piwik\Tracker\Response;
use Piwik\Tracker\ScheduledTasksRunner;
use Piwik\Tracker\Visit;
use Piwik\Tracker\VisitInterface;

/**
 * Class used by the logging script piwik.php called by the javascript tag.
 * Handles the visitor & his/her actions on the website, saves the data in the DB,
 * saves information in the cookie, etc.
 *
 * We try to include as little files as possible (no dependency on 3rd party modules).
 *
 */
class Tracker
{
    protected $stateValid = self::STATE_NOTHING_TO_NOTICE;
    /**
     * @var Db
     */
    protected static $db = null;

    const STATE_NOTHING_TO_NOTICE = 1;
    const STATE_LOGGING_DISABLE = 10;
    const STATE_EMPTY_REQUEST = 11;
    const STATE_NOSCRIPT_REQUEST = 13;

    // We use hex ID that are 16 chars in length, ie. 64 bits IDs
    const LENGTH_HEX_ID_STRING = 16;
    const LENGTH_BINARY_ID = 8;

    protected static $pluginsNotToLoad = array();
    protected static $pluginsToLoad = array();

    public static $initTrackerMode = false;
    private $transactionId;

    /**
     * The number of requests that have been successfully logged.
     *
     * @var int
     */
    private $countOfLoggedRequests = 0;

    private $timer;

    public function clear()
    {
        $this->stateValid = self::STATE_NOTHING_TO_NOTICE;
    }

    public function isEnabled()
    {
        return (!defined('PIWIK_ENABLE_TRACKING') || PIWIK_ENABLE_TRACKING);
    }

    public function setUp()
    {
        \Piwik\FrontController::createConfigObject();

        $GLOBALS['PIWIK_TRACKER_DEBUG'] = (bool) \Piwik\Config::getInstance()->Tracker['debug'];
        if ($GLOBALS['PIWIK_TRACKER_DEBUG'] === true) {
            require_once PIWIK_INCLUDE_PATH . '/core/Error.php';
            \Piwik\Error::setErrorHandler();
            require_once PIWIK_INCLUDE_PATH . '/core/ExceptionHandler.php';
            \Piwik\ExceptionHandler::setUp();

            $this->timer = new Timer();
            Common::printDebug("Debug enabled - Input parameters: ");
            Common::printDebug(var_export($_GET, true));

            TrackerDb::enableProfiling();
        }
    }

    public function tearDown()
    {
        if ($this->isDebugModeEnabled()) {
            Common::printDebug($_COOKIE);
            Common::printDebug((string)$this->timer);
        }
    }

    /**
     * Do not load the specified plugins (used during testing, to disable Provider plugin)
     * @param array $plugins
     */
    public static function setPluginsNotToLoad($plugins)
    {
        self::$pluginsNotToLoad = $plugins;
    }

    /**
     * Get list of plugins to not load
     *
     * @return array
     */
    public static function getPluginsNotToLoad()
    {
        return self::$pluginsNotToLoad;
    }

    /**
     * Update Tracker config
     *
     * @param string $name Setting name
     * @param mixed $value Value
     */
    private static function updateTrackerConfig($name, $value)
    {
        $section = Config::getInstance()->Tracker;
        $section[$name] = $value;
        Config::getInstance()->Tracker = $section;
    }

    /**
     * Main - tracks the visit/action
     *
     * @param Tracker\Requests $requests
     * @param Tracker\Response $response
     */
    public function main($requests, $response)
    {
        if (!SettingsPiwik::isPiwikInstalled()) {
            $this->handleEmptyRequest();
            return;
        }

        if (!empty($requests)) {
            if ($requests->isUsingBulkRequest() && $this->isTransactionSupported()) {
                $this->beginTransaction();
            }

            $isAuthenticated = false;

            try {
                foreach ($requests->getRequests() as $request) {
                    $isAuthenticated = $this->trackRequest($request, $requests->getTokenAuth());
                }

                $this->commitTransaction();

                $this->runScheduledTasksIfAllowed($isAuthenticated);

            } catch (DbException $e) {
                $this->handleException($e, $response, 500);
            } catch (UnexpectedWebsiteFoundException $e) {
                $this->handleException($e, $response, 400);
            } catch (InvalidRequestParameterException $e) {
                $this->handleException($e, $response, 400);
            } catch (Exception $e) {
                $this->handleException($e, $response, 500);
            }

        } else {
            $this->handleEmptyRequest();
        }

        Piwik::postEvent('Tracker.end');

        $response->outputResponse($this);

        self::disconnectDatabase();
    }

    private function handleException(Exception $e, Response $response, $responseCode)
    {
        $this->rollbackTransaction();
        Common::printDebug("Exception: " . $e->getMessage());
        $response->outputException($this, $e, $responseCode);
    }

    protected function beginTransaction()
    {
        if (!empty($this->transactionId)) {
            return;
        }

        $this->transactionId = self::getDatabase()->beginTransaction();
    }

    protected function commitTransaction()
    {
        if (empty($this->transactionId)) {
            return;
        }
        self::getDatabase()->commit($this->transactionId);
        $this->transactionId = null;
    }

    protected function rollbackTransaction()
    {
        if (empty($this->transactionId)) {
            return;
        }
        self::getDatabase()->rollback($this->transactionId);
    }

    /**
     * @return bool
     */
    protected function isTransactionSupported()
    {
        return (bool)Config::getInstance()->Tracker['bulk_requests_use_transaction'];
    }

    /**
     * Used to initialize core Piwik components on a piwik.php request
     * Eg. when cache is missed and we will be calling some APIs to generate cache
     */
    public static function initCorePiwikInTrackerMode()
    {
        if (SettingsServer::isTrackerApiRequest()
            && self::$initTrackerMode === false
        ) {
            self::$initTrackerMode = true;
            require_once PIWIK_INCLUDE_PATH . '/core/Option.php';

            Access::getInstance();
            Config::getInstance();

            try {
                Db::get();
            } catch (Exception $e) {
                Db::createDatabaseObject();
            }

            \Piwik\Plugin\Manager::getInstance()->loadCorePluginsDuringTracker();
        }
    }

    public function getCountOfLoggedRequests()
    {
        return $this->countOfLoggedRequests;
    }

    /**
     * Returns the date in the "Y-m-d H:i:s" PHP format
     *
     * @param int $timestamp
     * @return string
     */
    public static function getDatetimeFromTimestamp($timestamp)
    {
        return date("Y-m-d H:i:s", $timestamp);
    }

    /**
     * Initialization
     * @param Request $request
     */
    protected function init(Request $request)
    {
        $this->loadTrackerPlugins($request);
        $this->handleDisabledTracker();
        $this->handleEmptyRequest($request);
    }

    public function isDatabaseConnected()
    {
        return !is_null(self::$db);
    }

    public static function getDatabase()
    {
        if (is_null(self::$db)) {
            try {
                self::$db = TrackerDb::connectPiwikTrackerDb();
            } catch (Exception $e) {
                throw new DbException($e->getMessage(), $e->getCode());
            }
        }

        return self::$db;
    }

    public static function disconnectDatabase()
    {
        if (!is_null(self::$db)) {
            self::$db->disconnect();
            self::$db = null;
        }
    }

    /**
     * Returns the Tracker_Visit object.
     * This method can be overwritten to use a different Tracker_Visit object
     *
     * @throws Exception
     * @return \Piwik\Tracker\Visit
     */
    protected function getNewVisitObject()
    {
        $visit = null;

        /**
         * Triggered before a new **visit tracking object** is created. Subscribers to this
         * event can force the use of a custom visit tracking object that extends from
         * {@link Piwik\Tracker\VisitInterface}.
         *
         * @param \Piwik\Tracker\VisitInterface &$visit Initialized to null, but can be set to
         *                                              a new visit object. If it isn't modified
         *                                              Piwik uses the default class.
         */
        Piwik::postEvent('Tracker.makeNewVisitObject', array(&$visit));

        if (is_null($visit)) {
            $visit = new Visit();
        } elseif (!($visit instanceof VisitInterface)) {
            throw new Exception("The Visit object set in the plugin must implement VisitInterface");
        }
        return $visit;
    }

    public function shouldPerformRedirectToUrl(Requests $requests)
    {
        if (!$requests->hasRedirectUrl()) {
            return false;
        }

        if ($requests->hasRequests()) {
            return false;
        }

        $redirectUrl = $requests->getRedirectUrl();
        $host        = Url::getHostFromUrl($redirectUrl);

        if (empty($host)) {
            return false;
        }

        $urls     = new SiteUrls();
        $siteUrls = $urls->getAllCachedSiteUrls();
        $siteIds  = $requests->getAllSiteIdsWithinRequest();

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

    protected function outputTransparentGif ()
    {
        $transGifBase64 = "R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==";
        Common::sendHeader('Content-Type: image/gif');

        print(base64_decode($transGifBase64));
    }

    protected function isVisitValid()
    {
        return $this->stateValid !== self::STATE_LOGGING_DISABLE
        && $this->stateValid !== self::STATE_EMPTY_REQUEST;
    }

    public function getState()
    {
        return $this->stateValid;
    }

    public function isLoggingDisabled()
    {
        return $this->getState() === self::STATE_LOGGING_DISABLE;
    }

    public function isDebugModeEnabled()
    {
        return array_key_exists('PIWIK_TRACKER_DEBUG', $GLOBALS) && $GLOBALS['PIWIK_TRACKER_DEBUG'] === true;
    }

    protected function setState($value)
    {
        $this->stateValid = $value;
    }

    protected function loadTrackerPlugins(Request $request)
    {
        // Adding &dp=1 will disable the provider plugin, if token_auth is used (used to speed up bulk imports)
        $disableProvider = $request->getParam('dp');
        if (!empty($disableProvider)) {
            Tracker::setPluginsNotToLoad(array('Provider'));
        }

        try {
            $pluginsTracker = \Piwik\Plugin\Manager::getInstance()->loadTrackerPlugins();
            Common::printDebug("Loading plugins: { " . implode(", ", $pluginsTracker) . " }");
        } catch (Exception $e) {
            Common::printDebug("ERROR: " . $e->getMessage());
        }
    }

    protected function handleEmptyRequest(Request $request = null)
    {
        if (is_null($request)) {
            $request = new Request($_GET + $_POST);
        }
        $countParameters = $request->getParamsCount();
        if ($countParameters == 0) {
            $this->setState(self::STATE_EMPTY_REQUEST);
        }
        if ($countParameters == 1) {
            $this->setState(self::STATE_NOSCRIPT_REQUEST);
        }
    }

    protected function handleDisabledTracker()
    {
        $saveStats = Config::getInstance()->Tracker['record_statistics'];
        if ($saveStats == 0) {
            $this->setState(self::STATE_LOGGING_DISABLE);
        }
    }

    public static function setTestEnvironment($args = null, $requestMethod = null)
    {
        if (is_null($args)) {
            $requests = new Requests();
            $postData = $requests->getRequestsArrayFromBulkRequest($requests->getRawBulkRequest());
            $args     = $_GET + $postData;
        }
        if (is_null($requestMethod) && array_key_exists('REQUEST_METHOD', $_SERVER)) {
            $requestMethod = $_SERVER['REQUEST_METHOD'];
        } else if (is_null($requestMethod)) {
            $requestMethod = 'GET';
        }

        // Do not run scheduled tasks during tests
        self::updateTrackerConfig('scheduled_tasks_min_interval', 0);

        // if nothing found in _GET/_POST and we're doing a POST, assume bulk request. in which case,
        // we have to bypass authentication
        if (empty($args) && $requestMethod == 'POST') {
            self::updateTrackerConfig('tracking_requests_require_authentication', 0);
        }

        // Tests can force the use of 3rd party cookie for ID visitor
        if (Common::getRequestVar('forceUseThirdPartyCookie', false, null, $args) == 1) {
            self::updateTrackerConfig('use_third_party_id_cookie', 1);
        }

        // Tests using window_look_back_for_visitor
        if (Common::getRequestVar('forceLargeWindowLookBackForVisitor', false, null, $args) == 1
            // also look for this in bulk requests (see fake_logs_replay.log)
            || strpos(json_encode($args, true), '"forceLargeWindowLookBackForVisitor":"1"') !== false
        ) {
            self::updateTrackerConfig('window_look_back_for_visitor', 2678400);
        }

        // Tests can force the enabling of IP anonymization
        if (Common::getRequestVar('forceIpAnonymization', false, null, $args) == 1) {

            self::getDatabase(); // make sure db is initialized

            $privacyConfig = new PrivacyManagerConfig();
            $privacyConfig->ipAddressMaskLength = 2;

            \Piwik\Plugins\PrivacyManager\IPAnonymizer::activate();
        }

        $pluginsDisabled = array('Provider');

        // Disable provider plugin, because it is so slow to do many reverse ip lookups
        self::setPluginsNotToLoad($pluginsDisabled);
    }

    /**
     * @param $params
     * @param $tokenAuth
     * @return array
     */
    protected function trackRequest($params, $tokenAuth)
    {
        if ($params instanceof Request) {
            $request = $params;
        } else {
            $request = new Request($params, $tokenAuth);
        }

        $this->init($request);

        $isAuthenticated = $request->isAuthenticated();

        if ($this->isVisitValid()) {
            Common::printDebug("Current datetime: " . date("Y-m-d H:i:s", $request->getCurrentTimestamp()));

            $visit = $this->getNewVisitObject();
            $visit->setRequest($request);
            $visit->handle();
        } else {
            Common::printDebug("The request is invalid: empty request, or maybe tracking is disabled in the config.ini.php via record_statistics=0");
        }

        $this->clear();

        // increment successfully logged request count. make sure to do this after try-catch,
        // since an excluded visit is considered 'successfully logged'
        ++$this->countOfLoggedRequests;
        return $isAuthenticated;
    }

    protected function runScheduledTasksIfAllowed($isAuthenticated)
    {
        // Do not run schedule task if we are importing logs
        // or doing custom tracking (as it could slow down)
        if (!$isAuthenticated) {

            $tasks = new ScheduledTasksRunner();
            if ($tasks->shouldRun($this)) {
                $tasks->runScheduledTasks();
            }
        }
    }

}
