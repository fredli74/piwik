<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Tracker;

use Piwik\Tracker\TrackerConfig;
use Piwik\Tracker;
use Piwik\Tracker\Queue\Backend\Redis;
use Piwik\Tracker\Queue;
use Piwik\Translate;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\Requests;
use Piwik\Tracker\Request;

/**
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

    public function test_internalBuildRequests_ShouldReturnRequestObjects()
    {
        $this->assertTrue($this->buildNumRequests(0) instanceof Requests);
        $this->assertEquals(array(), $this->buildNumRequests(0)->getRequests());

        $this->assertTrue($this->buildNumRequests(3) instanceof Requests);

        $this->assertEquals(array(
            new Request(array('idsite' => 1)),
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 3)),
        ), $this->buildNumRequests(3)->getRequests());

        $this->assertTrue($this->buildNumRequests(10) instanceof Requests);
        $this->assertCount(10, $this->buildNumRequests(10)->getRequests());
    }

    public function test_internalBuildRequests_ShouldBeAbleToSpecifyTheSiteId()
    {
        $this->assertEquals(array(
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 2)),
        ), $this->buildNumRequests(3, 2)->getRequests());
    }

    public function test_internalBuildManyRequestsContainingRequests_ShouldReturnManyRequestObjects()
    {
        $this->assertEquals(array(), $this->buildManyRequestsContainingRequests(0));
        $this->assertEquals(array($this->buildNumRequests(1)), $this->buildManyRequestsContainingRequests(1));

        $this->assertManyRequestsAreEqual(array(
            $this->buildNumRequests(1),
            $this->buildNumRequests(1, 2),
            $this->buildNumRequests(1, 3),
            $this->buildNumRequests(1, 4),
            $this->buildNumRequests(1, 5),
        ), $this->buildManyRequestsContainingRequests(5));
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

    public function test_addRequest_ShouldNotAddAnything_IfNoRequestsGiven()
    {
        $this->queue->addRequest(new Requests());
        $this->assertEquals(array(), $this->queue->getRequestsToProcess());
    }

    public function test_addRequest_ShouldBeAble_ToAddARequest()
    {
        $this->queue->addRequest($this->buildNumRequests(1));

        $this->assertManyRequestsAreEqual(array($this->buildNumRequests(1)), $this->queue->getRequestsToProcess());
    }

    public function test_addRequest_ShouldBeAble_ToAddARequestWithManyRequests()
    {
        $this->queue->addRequest($this->buildNumRequests(2));
        $this->queue->addRequest($this->buildNumRequests(1));

        $expected = array(
            $this->buildNumRequests(2),
            $this->buildNumRequests(1)
        );
        $this->assertManyRequestsAreEqual($expected, $this->queue->getRequestsToProcess());
    }

    public function test_shouldProcess_ShouldReturnValue_WhenQueueIsEmptyOrHasTooLessRequests()
    {
        $this->assertFalse($this->queue->shouldProcess());
    }

    public function test_shouldProcess_ShouldReturnTrue_OnceNumberOfRequestsAreAvailable()
    {
        // 2 < 3 should return false
        $this->addNumberOfRequestsToQueue(2);
        $this->assertFalse($this->queue->shouldProcess());

        // now min number of requests is reached
        $this->addNumberOfRequestsToQueue(1);
        $this->assertTrue($this->queue->shouldProcess());

        // when there are more than 3 requests (5) should still return true
        $this->addNumberOfRequestsToQueue(2);
        $this->assertTrue($this->queue->shouldProcess());
    }

    public function test_shouldProcess_ShouldReturnTrue_AsLongAsThereAreEnoughRequestsInQueue()
    {
        // 5 > 3 so should process
        $this->addNumberOfRequestsToQueue(5);
        $this->assertTrue($this->queue->shouldProcess());

        // should no longer process as now 5 - 3 = 2 requests are in queue
        $this->queue->markRequestsAsProcessed();
        $this->assertFalse($this->queue->shouldProcess());

        // 6 + 2 = 8 which > 3
        $this->addNumberOfRequestsToQueue(6);
        $this->assertTrue($this->queue->shouldProcess());

        // 8 - 3 = 5 which > 3
        $this->queue->markRequestsAsProcessed();
        $this->assertTrue($this->queue->shouldProcess());

        // 5 - 3 = 2 which < 3
        $this->queue->markRequestsAsProcessed();
        $this->assertFalse($this->queue->shouldProcess());
    }

    public function test_getRequestsToProcess_shouldReturnAnEmptyArrayIfQueueIsEmpty()
    {
        $this->assertEquals(array(), $this->queue->getRequestsToProcess());
    }

    public function test_getRequestsToProcess_shouldReturnAllRequestsIfThereAreLessThanRequired()
    {
        $this->queue->addRequest($this->buildNumRequests(2));

        $requests = $this->queue->getRequestsToProcess();
        $expected = array($this->buildNumRequests(2));

        $this->assertManyRequestsAreEqual($expected, $requests);
    }

    public function test_getRequestsToProcess_shouldReturnOnlyTheRequestsThatActuallyNeedToBeProcessed_IfQueueContainsMoreEntries()
    {
        $this->addNumberOfRequestsToQueue(10);

        $requests = $this->queue->getRequestsToProcess();
        $expected = $this->buildManyRequestsContainingRequests(3);

        $this->assertManyRequestsAreEqual($expected, $requests);
    }

    public function test_getRequestsToProcess_shouldNotRemoveAnyEntriesFromTheQueue()
    {
        $this->addNumberOfRequestsToQueue(5);

        $expected = $this->buildManyRequestsContainingRequests(3);

        $this->assertManyRequestsAreEqual($expected, $this->queue->getRequestsToProcess());
        $this->assertManyRequestsAreEqual($expected, $this->queue->getRequestsToProcess());
        $this->assertManyRequestsAreEqual($expected, $this->queue->getRequestsToProcess());
    }

    public function test_markRequestsAsProcessed_ShouldNotFail_IfQueueIsEmpty()
    {
        $this->queue->markRequestsAsProcessed();
        $this->assertEquals(array(), $this->queue->getRequestsToProcess());
    }

    public function test_markRequestsAsProcessed_ShouldRemoveTheConfiguredNumberOfRequests()
    {
        $this->addNumberOfRequestsToQueue(5);

        $expected = array(
            $this->buildNumRequests(1, 1),
            $this->buildNumRequests(1, 2),
            $this->buildNumRequests(1, 3)
        );

        $this->assertManyRequestsAreEqual($expected, $this->queue->getRequestsToProcess());

        $this->queue->markRequestsAsProcessed();

        $expected = array(
            $this->buildNumRequests(1, 4),
            $this->buildNumRequests(1, 5)
        );

        $this->assertManyRequestsAreEqual($expected, $this->queue->getRequestsToProcess());

        $this->queue->markRequestsAsProcessed();
        $this->assertEquals(array(), $this->queue->getRequestsToProcess());
    }

    /**
     * @param Requests[] $expected
     * @param Requests[] $actual
     */
    private function assertManyRequestsAreEqual(array $expected, array $actual)
    {
        $this->assertSameSize($expected, $actual);

        foreach ($expected as $index => $item) {
            $this->assertRequestsAreEqual($item, $actual[$index]);
        }
    }

    private function assertRequestsAreEqual(Requests $expected, Requests $actual)
    {
        $eState = $expected->getState();
        $aState = $expected->getState();

        $eTime = $eState['time'];
        $aTime = $aState['time'];

        unset($eState['time']);
        unset($aState['time']);

        if (array_key_exists('REQUEST_TIME_FLOAT', $_SERVER)) {
            unset($eState['REQUEST_TIME_FLOAT']);
            unset($aState['REQUEST_TIME_FLOAT']);
        }

        $this->assertTrue(($aTime - 5 < $eTime) && ($aTime + 5 > $eTime), "$eTime is not nearly $aTime");
        $this->assertEquals($eState, $aState);
    }

    private function buildNumRequests($numRequests, $idToUse = null)
    {
        $req = new Requests();

        $requests = array();
        for ($index = 1; $index <= $numRequests; $index++) {
            $requests[] = array('idsite' => $idToUse ?: $index);
        }

        $req->setRequests($requests);
        $req->rememberEnvironment();

        return $req;
    }

    private function buildManyRequestsContainingRequests($numRequests)
    {
        $requests = array();
        for ($index = 1; $index <= $numRequests; $index++) {
            $requests[] = $this->buildNumRequests(1, $index);
        }

        return $requests;
    }

    private function addNumberOfRequestsToQueue($numRequests)
    {
        for ($index = 1; $index <= $numRequests; $index++) {
            $this->queue->addRequest($this->buildNumRequests(1, $index));
        }
    }

}
