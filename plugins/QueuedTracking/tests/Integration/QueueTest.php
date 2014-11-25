<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;

use Piwik\Tracker\TrackerConfig;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Translate;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\RequestSet;
use Piwik\Tracker\Request;

/**
 * @group QueuedTracking
 * @group Queue
 * @group QueueTest
 * @group Tracker
 * @group Redis
 */
class QueueTest extends IntegrationTestCase
{
    /**
     * @var Queue
     */
    private $queue;

    public function setUp()
    {
        parent::setUp();

        Redis::enableTestMode();
        $this->queue = new Queue();
        $this->queue->setNumberOfRequestsToProcessAtSameTime(3);
    }

    public function tearDown()
    {
        Redis::clearDatabase();
        parent::tearDown();
    }

    public function test_internalBuildRequestsSet_ShouldReturnRequestObjects()
    {
        $this->assertTrue($this->buildRequestSet(0) instanceof RequestSet);
        $this->assertEquals(array(), $this->buildRequestSet(0)->getRequests());

        $this->assertTrue($this->buildRequestSet(3) instanceof RequestSet);

        $this->assertEquals(array(
            new Request(array('idsite' => 1)),
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 3)),
        ), $this->buildRequestSet(3)->getRequests());

        $this->assertTrue($this->buildRequestSet(10) instanceof RequestSet);
        $this->assertCount(10, $this->buildRequestSet(10)->getRequests());
    }

    public function test_internalBuildRequestsSet_ShouldBeAbleToSpecifyTheSiteId()
    {
        $this->assertEquals(array(
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 2)),
        ), $this->buildRequestSet(3, 2)->getRequests());
    }

    public function test_internalBuildManyRequestsContainingRequests_ShouldReturnManyRequestObjects()
    {
        $this->assertEquals(array(), $this->buildManyRequestSets(0));
        $this->assertEquals(array($this->buildRequestSet(1)), $this->buildManyRequestSets(1));

        $this->assertManyRequestSetsAreEqual(array(
            $this->buildRequestSet(1),
            $this->buildRequestSet(1, 2),
            $this->buildRequestSet(1, 3),
            $this->buildRequestSet(1, 4),
            $this->buildRequestSet(1, 5),
        ), $this->buildManyRequestSets(5));
    }

    public function test_isEnabled_ShouldReturnFalse_IfDisabled_WhichItShouldBeByDefault()
    {
        $this->assertFalse($this->queue->isEnabled());
    }

    public function test_isEnabled_ShouldReturnTrue_IfEnabled()
    {
        $value = TrackerConfig::getConfigValue('queue_enabled');
        TrackerConfig::setConfigValue('queue_enabled', 1);

        $this->assertTrue($this->queue->isEnabled());

        TrackerConfig::setConfigValue('queue_enabled', $value);
    }

    public function test_addRequestSet_ShouldNotAddAnything_IfNoRequestsGiven()
    {
        $this->queue->addRequestSet(new RequestSet());
        $this->assertEquals(array(), $this->queue->getRequestSetsToProcess());
    }

    public function test_addRequestSet_ShouldBeAble_ToAddARequest()
    {
        $this->queue->addRequestSet($this->buildRequestSet(1));

        $this->assertManyRequestSetsAreEqual(array($this->buildRequestSet(1)), $this->queue->getRequestSetsToProcess());
    }

    public function test_addRequestSet_ShouldBeAble_ToAddARequestWithManyRequests()
    {
        $this->queue->addRequestSet($this->buildRequestSet(2));
        $this->queue->addRequestSet($this->buildRequestSet(1));

        $expected = array(
            $this->buildRequestSet(2),
            $this->buildRequestSet(1)
        );
        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());
    }

    public function test_shouldProcess_ShouldReturnValue_WhenQueueIsEmptyOrHasTooLessRequests()
    {
        $this->assertFalse($this->queue->shouldProcess());
    }

    public function test_shouldProcess_ShouldReturnTrue_OnceNumberOfRequestsAreAvailable()
    {
        // 2 < 3 should return false
        $this->addRequestSetsToQueue(2);
        $this->assertFalse($this->queue->shouldProcess());

        // now min number of requests is reached
        $this->addRequestSetsToQueue(1);
        $this->assertTrue($this->queue->shouldProcess());

        // when there are more than 3 requests (5) should still return true
        $this->addRequestSetsToQueue(2);
        $this->assertTrue($this->queue->shouldProcess());
    }

    public function test_shouldProcess_ShouldReturnTrue_AsLongAsThereAreEnoughRequestsInQueue()
    {
        // 5 > 3 so should process
        $this->addRequestSetsToQueue(5);
        $this->assertTrue($this->queue->shouldProcess());

        // should no longer process as now 5 - 3 = 2 requests are in queue
        $this->queue->markRequestSetsAsProcessed();
        $this->assertFalse($this->queue->shouldProcess());

        // 6 + 2 = 8 which > 3
        $this->addRequestSetsToQueue(6);
        $this->assertTrue($this->queue->shouldProcess());

        // 8 - 3 = 5 which > 3
        $this->queue->markRequestSetsAsProcessed();
        $this->assertTrue($this->queue->shouldProcess());

        // 5 - 3 = 2 which < 3
        $this->queue->markRequestSetsAsProcessed();
        $this->assertFalse($this->queue->shouldProcess());
    }

    public function test_getRequestSetsToProcess_shouldReturnAnEmptyArrayIfQueueIsEmpty()
    {
        $this->assertEquals(array(), $this->queue->getRequestSetsToProcess());
    }

    public function test_getRequestSetsToProcess_shouldReturnAllRequestsIfThereAreLessThanRequired()
    {
        $this->queue->addRequestSet($this->buildRequestSet(2));

        $requests = $this->queue->getRequestSetsToProcess();
        $expected = array($this->buildRequestSet(2));

        $this->assertManyRequestSetsAreEqual($expected, $requests);
    }

    public function test_getRequestSetsToProcess_shouldReturnOnlyTheRequestsThatActuallyNeedToBeProcessed_IfQueueContainsMoreEntries()
    {
        $this->addRequestSetsToQueue(10);

        $requests = $this->queue->getRequestSetsToProcess();
        $expected = $this->buildManyRequestSets(3);

        $this->assertManyRequestSetsAreEqual($expected, $requests);
    }

    public function test_getRequestSetsToProcess_shouldNotRemoveAnyEntriesFromTheQueue()
    {
        $this->addRequestSetsToQueue(5);

        $expected = $this->buildManyRequestSets(3);

        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());
        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());
        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());
    }

    public function test_markRequestSetsAsProcessed_ShouldNotFail_IfQueueIsEmpty()
    {
        $this->queue->markRequestSetsAsProcessed();
        $this->assertEquals(array(), $this->queue->getRequestSetsToProcess());
    }

    public function test_markRequestSetsAsProcessed_ShouldRemoveTheConfiguredNumberOfRequests()
    {
        $this->addRequestSetsToQueue(5);

        $expected = array(
            $this->buildRequestSet(1, 1),
            $this->buildRequestSet(1, 2),
            $this->buildRequestSet(1, 3)
        );

        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());

        $this->queue->markRequestSetsAsProcessed();

        $expected = array(
            $this->buildRequestSet(1, 4),
            $this->buildRequestSet(1, 5)
        );

        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());

        $this->queue->markRequestSetsAsProcessed();
        $this->assertEquals(array(), $this->queue->getRequestSetsToProcess());
    }

    /**
     * @param RequestSet[] $expected
     * @param RequestSet[] $actual
     */
    private function assertManyRequestSetsAreEqual(array $expected, array $actual)
    {
        $this->assertSameSize($expected, $actual);

        foreach ($expected as $index => $item) {
            $this->assertRequestsAreEqual($item, $actual[$index]);
        }
    }

    private function assertRequestsAreEqual(RequestSet $expected, RequestSet $actual)
    {
        $eState = $expected->getState();
        $aState = $actual->getState();

        $eTime = $eState['time'];
        $aTime = $aState['time'];

        unset($eState['time']);
        unset($aState['time']);

        if (array_key_exists('REQUEST_TIME_FLOAT', $eState)) {
            unset($eState['REQUEST_TIME_FLOAT']);
        }

        if (array_key_exists('REQUEST_TIME_FLOAT', $aState)) {
            unset($aState['REQUEST_TIME_FLOAT']);
        }

        $this->assertGreaterThan(100000, $aTime);
        $this->assertTrue(($aTime - 5 < $eTime) && ($aTime + 5 > $eTime), "$eTime is not nearly $aTime");
        $this->assertEquals($eState, $aState);
    }

    private function buildRequestSet($numRequests, $idSite = null)
    {
        $req = new RequestSet();

        $requests = array();
        for ($index = 1; $index <= $numRequests; $index++) {
            $requests[] = array('idsite' => $idSite ?: $index);
        }

        $req->setRequests($requests);
        $req->rememberEnvironment();

        return $req;
    }

    private function buildManyRequestSets($numRequestSets)
    {
        $requests = array();
        for ($index = 1; $index <= $numRequestSets; $index++) {
            $requests[] = $this->buildRequestSet(1, $index);
        }

        return $requests;
    }

    private function addRequestSetsToQueue($numRequestSets)
    {
        for ($index = 1; $index <= $numRequestSets; $index++) {
            $this->queue->addRequestSet($this->buildRequestSet(1, $index));
        }
    }

}
