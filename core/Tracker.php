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
use Piwik\Plugins\BulkTracking\Tracker\Requests;
use Piwik\Plugins\PrivacyManager\Config as PrivacyManagerConfig;
use Piwik\Tracker\Db as TrackerDb;
use Piwik\Tracker\Db\DbException;
use Piwik\Tracker\Handler;
use Piwik\Tracker\Request;
use Piwik\Tracker\RequestSet;
use Piwik\Tracker\TrackerConfig;
use Piwik\Tracker\Visit;
use Piwik\Plugin\Manager as PluginManager;

/**
 * Class used by the logging script piwik.php called by the javascript tag.
 * Handles the visitor & his/her actions on the website, saves the data in the DB,
 * saves information in the cookie, etc.
 *
 * We try to include as little files as possible (no dependency on 3rd party modules).
 */
class Tracker
{
    /**
     * @var Db
     */
    private static $db = null;

    // We use hex ID that are 16 chars in length, ie. 64 bits IDs
    const LENGTH_HEX_ID_STRING = 16;
    const LENGTH_BINARY_ID = 8;

    public static $initTrackerMode = false;

    private $countOfLoggedRequests = 0;
    protected $isInstalled = null;

    public function isDebugModeEnabled()
    {
        return array_key_exists('PIWIK_TRACKER_DEBUG', $GLOBALS) && $GLOBALS['PIWIK_TRACKER_DEBUG'] === true;
    }

    public function shouldRecordStatistics()
    {
        $record = TrackerConfig::getConfigValue('record_statistics') != 0;

        if (!$record) {
            Common::printDebug('Tracking is disabled in the config.ini.php via record_statistics=0');
        }

        return $record && $this->isInstalled();
    }

    private function init()
    {
        \Piwik\FrontController::createConfigObject();

        $GLOBALS['PIWIK_TRACKER_DEBUG'] = (bool) TrackerConfig::getConfigValue('debug');

        if ($this->isDebugModeEnabled()) {
            Error::setErrorHandler();
            ExceptionHandler::setUp();

            Common::printDebug("Debug enabled - Input parameters: ");
            Common::printDebug(var_export($_GET, true));
        }
    }

    public function isInstalled()
    {
        if (is_null($this->isInstalled)) {
            $this->isInstalled = SettingsPiwik::isPiwikInstalled();
        }

        return $this->isInstalled;
    }

    public function main(Handler $handler, RequestSet $requestSet)
    {
        $this->init();
        $handler->init($this, $requestSet);

        $this->track($handler, $requestSet);

        Piwik::postEvent('Tracker.end');
        $handler->finish($this, $requestSet);

        $this->disconnectDatabase();
    }

    public function track(Handler $handler, RequestSet $requestSet)
    {
        if (!$this->shouldRecordStatistics()) {
            return;
        }

        $handler->initTrackingRequests($this, $requestSet);

        if ($requestSet->hasRequests()) {
            try {

                $handler->onStartTrackRequests($this, $requestSet);
                $handler->process($this, $requestSet);
                $handler->onAllRequestsTracked($this, $requestSet);

            } catch (Exception $e) {
                $this->disconnectDatabase();
                $handler->onException($this, $e);
            }
        }
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

    public function setCountOfLoggedRequests($numLoggedRequests)
    {
        $this->countOfLoggedRequests = $numLoggedRequests;
    }

    public function hasLoggedRequests()
    {
        return 0 !== $this->countOfLoggedRequests;
    }

    /**
     * @deprecated since 2.10.0 use {@link Date::getDatetimeFromTimestamp()} instead
     */
    public static function getDatetimeFromTimestamp($timestamp)
    {
        return Date::getDatetimeFromTimestamp($timestamp);
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

    protected function disconnectDatabase()
    {
        if ($this->isDatabaseConnected()) { // note: I think we do this only for the tests
            self::$db->disconnect();
            self::$db = null;
        }
    }

    public static function setTestEnvironment($args = null, $requestMethod = null)
    {
        if (is_null($args)) {
            $requests = new Requests();
            $args     = $requests->getRequestsArrayFromBulkRequest($requests->getRawBulkRequest());
            array_unshift($args, $_GET);
        }

        if (is_null($requestMethod) && array_key_exists('REQUEST_METHOD', $_SERVER)) {
            $requestMethod = $_SERVER['REQUEST_METHOD'];
        } else if (is_null($requestMethod)) {
            $requestMethod = 'GET';
        }

        // Do not run scheduled tasks during tests
        TrackerConfig::setConfigValue('scheduled_tasks_min_interval', 0);

        // if nothing found in _GET/_POST and we're doing a POST, assume bulk request. in which case,
        // we have to bypass authentication
        if (empty($args) && $requestMethod == 'POST') {
            TrackerConfig::setConfigValue('tracking_requests_require_authentication', 0);
        }

        // Tests can force the use of 3rd party cookie for ID visitor
        if (Common::getRequestVar('forceUseThirdPartyCookie', false, null, $args) == 1) {
            TrackerConfig::setConfigValue('use_third_party_id_cookie', 1);
        }

        // Tests using window_look_back_for_visitor
        if (Common::getRequestVar('forceLargeWindowLookBackForVisitor', false, null, $args) == 1
            // also look for this in bulk requests (see fake_logs_replay.log)
            || strpos(json_encode($args, true), '"forceLargeWindowLookBackForVisitor":"1"') !== false
        ) {
            TrackerConfig::setConfigValue('window_look_back_for_visitor', 2678400);
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
        \Piwik\Plugin\Manager::getInstance()->setTrackerPluginsNotToLoad($pluginsDisabled);
    }

    /**
     * @param Request $request
     * @return array
     */
    public function trackRequest(Request $request)
    {
        if ($request->isEmptyRequest()) {
            Common::printDebug("The request is empty");
        } else {
            $this->loadTrackerPlugins($request);

            Common::printDebug("Current datetime: " . date("Y-m-d H:i:s", $request->getCurrentTimestamp()));

            $visit = Visit\Factory::make();
            $visit->setRequest($request);
            $visit->handle();
        }

        // increment successfully logged request count. make sure to do this after try-catch,
        // since an excluded visit is considered 'successfully logged'
        ++$this->countOfLoggedRequests;
    }

    private function loadTrackerPlugins(Request $request)
    {
        $pluginManager = PluginManager::getInstance();

        // Adding &dp=1 will disable the provider plugin, this is an "unofficial" parameter used to speed up log importer
        $disableProvider = $request->getParam('dp');
        if (!empty($disableProvider)) {
            $pluginManager->setTrackerPluginsNotToLoad(array('Provider'));
        } else {
            $pluginManager->setTrackerPluginsNotToLoad(array());
        }

        try {
            $pluginsTracker = $pluginManager->loadTrackerPlugins();
            Common::printDebug("Loading plugins: { " . implode(", ", $pluginsTracker) . " }");
        } catch (Exception $e) {
            Common::printDebug("ERROR: " . $e->getMessage());
        }
    }

}
