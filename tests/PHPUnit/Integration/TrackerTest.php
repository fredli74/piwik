<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration;

use Piwik\Common;
use Piwik\EventDispatcher;
use Piwik\Piwik;
use Piwik\Plugin;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\Tracker\Handler;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker;
use Piwik\Tracker\Request;
use Piwik\Translate;

class TestTracker extends Tracker
{
    public function __construct()
    {
        $this->isInstalled = true;
    }

    public function setIsNotInstalled()
    {
        $this->isInstalled = false;
    }

    public function disconnectDatabase()
    {
        parent::disconnectDatabase();
    }
}

/**
 * @group TrackerTest
 * @group Tracker
 */
class TrackerTest extends IntegrationTestCase
{
    /**
     * @var TestTracker
     */
    private $tracker;

    /**
     * @var Request
     */
    private $request;

    public function setUp()
    {
        parent::setUp();

        Fixture::createWebsite('2014-01-01 00:00:00');

        $this->tracker = new TestTracker();
        $this->request = $this->buildRequest(array('idsite' => 1));
    }

    public function tearDown()
    {
        $this->tracker->disconnectDatabase();
        EventDispatcher::getInstance()->clearObservers('Tracker.makeNewVisitObject');
        parent::tearDown();
    }

    public function test_isInstalled_shouldReturnTrue_AsPiwikIsInstalled()
    {
        $this->assertTrue($this->tracker->isInstalled());
    }

    public function test_shouldRecordStatistics_shouldReturnTrue_IfEnabled_WhichItIsByDefault()
    {
        $this->assertTrue($this->tracker->shouldRecordStatistics());
    }

    public function test_shouldRecordStatistics_shouldReturnFalse_IfEnabledButNotInstalled()
    {
        $this->tracker->setIsNotInstalled();
        $this->assertFalse($this->tracker->shouldRecordStatistics());
    }

    public function test_shouldRecordStatistics_shouldReturnFalse_IfDisabledButInstalled()
    {
        $oldConfig = Tracker\TrackerConfig::getConfigValue('record_statistics');
        Tracker\TrackerConfig::setConfigValue('record_statistics', 0);

        $this->assertFalse($this->tracker->shouldRecordStatistics());

        Tracker\TrackerConfig::setConfigValue('record_statistics', $oldConfig); // reset
    }

    public function test_isDebugModeEnabled_main_shouldSetGlobalsDebugVar_WhichShouldBeDisabledByDefault()
    {
        $this->assertTrue(!array_key_exists('PIWIK_TRACKER_DEBUG', $GLOBALS));

        $this->tracker->main(new Handler(), new Tracker\RequestSet());
        $this->assertFalse($this->tracker->isDebugModeEnabled());

        $this->assertFalse($GLOBALS['PIWIK_TRACKER_DEBUG']);
        unset($GLOBALS['PIWIK_TRACKER_DEBUG']);
    }

    public function test_isDebugModeEnabled_main_shouldSetGlobalsDebugVar()
    {
        $this->assertTrue(!array_key_exists('PIWIK_TRACKER_DEBUG', $GLOBALS));

        $oldConfig = Tracker\TrackerConfig::getConfigValue('debug');
        Tracker\TrackerConfig::setConfigValue('debug', 1);

        $this->tracker->main(new Handler(), new Tracker\RequestSet());
        $this->assertTrue($this->tracker->isDebugModeEnabled());

        Tracker\TrackerConfig::setConfigValue('debug', $oldConfig); // reset

        $this->assertTrue($GLOBALS['PIWIK_TRACKER_DEBUG']);
        unset($GLOBALS['PIWIK_TRACKER_DEBUG']);
    }

    public function test_isDatabaseConnected_shouldReturnFalse_IfNotConnected()
    {
        $this->tracker->disconnectDatabase();

        $this->assertFalse($this->tracker->isDatabaseConnected());
    }

    public function test_getDatabase_shouldReturnDbInstance()
    {
        $db = $this->tracker->getDatabase();

        $this->assertInstanceOf('Piwik\\Tracker\\Db', $db);
    }

    public function test_isDatabaseConnected_shouldReturnTrue_WhenDbIsConnected()
    {
        $db = $this->tracker->getDatabase(); // make sure connected
        $this->assertNotEmpty($db);

        $this->assertTrue($this->tracker->isDatabaseConnected());
    }

    public function test_disconnectDatabase_shouldDisconnectDb()
    {
        $this->tracker->getDatabase(); // make sure connected
        $this->assertTrue($this->tracker->isDatabaseConnected());

        $this->tracker->disconnectDatabase();

        $this->assertFalse($this->tracker->isDatabaseConnected());
    }

    public function test_trackRequest_shouldNotTrackAnything_IfRequestIsEmpty()
    {
        $called = false;
        Piwik::addAction('Tracker.makeNewVisitObject', function () use (&$called) {
            $called = true;
        });

        $this->tracker->trackRequest(new Request(array()));

        $this->assertFalse($called);
    }

    public function test_trackRequest_shouldTrack_IfRequestIsNotEmpty()
    {
        $called = false;
        Piwik::addAction('Tracker.makeNewVisitObject', function () use (&$called) {
            $called = true;
        });

        $this->tracker->trackRequest($this->request);

        $this->assertTrue($called);
    }

    public function test_trackRequest_shouldNotLoadProviderPlugin_IfDpIsNotGiven()
    {
        $this->tracker->trackRequest($this->buildRequest(array('idsite' => 1, 'dp' => 1)));

        $plugins = Plugin\Manager::getInstance()->getTrackerPluginsNotToLoad();

        $this->assertEquals(array('Provider'), $plugins);

        // verify
        $this->assertFalse(Plugin\Manager::getInstance()->isPluginLoaded('Provider'));
    }

    public function test_trackRequest_shouldLoadProviderPlugin_IfDpIsNotGiven()
    {
        $this->tracker->trackRequest($this->request);

        $plugins = Plugin\Manager::getInstance()->getTrackerPluginsNotToLoad();

        $this->assertEquals(array(), $plugins);

        // verify
        $this->assertTrue(Plugin\Manager::getInstance()->isPluginLoaded('Provider'));
    }

    public function test_trackRequest_shouldIncreaseLoggedRequestsCounter()
    {
        $this->tracker->trackRequest($this->request);
        $this->assertSame(1, $this->tracker->getCountOfLoggedRequests());

        $this->tracker->trackRequest($this->request);
        $this->assertSame(2, $this->tracker->getCountOfLoggedRequests());
    }

    public function test_trackRequest_shouldIncreaseLoggedRequestsCounter_EvenIfRequestIsEmpty()
    {
        $request = $this->buildRequest(array());
        $this->assertTrue($request->isEmptyRequest());

        $this->tracker->trackRequest($request);
        $this->assertSame(1, $this->tracker->getCountOfLoggedRequests());

        $this->tracker->trackRequest($request);
        $this->assertSame(2, $this->tracker->getCountOfLoggedRequests());
    }

    public function test_trackRequest_shouldActuallyTrack()
    {
        $request = $this->buildRequest(array('idsite' => 1, 'url' => 'http://www.example.com', 'action_name' => 'test', 'rec' => 1));
        $this->tracker->trackRequest($request);

        $this->assertActionEquals('test', 1);
        $this->assertActionEquals('example.com', 2);
    }

    private function assertActionEquals($expected, $idaction)
    {
        $actionName = Tracker::getDatabase()->fetchOne("SELECT name FROM " . Common::prefixTable('log_action') . " WHERE idaction = ?", array($idaction));
        $this->assertEquals($expected, $actionName);
    }

    private function buildRequest($params)
    {
        return new Request($params);
    }

}