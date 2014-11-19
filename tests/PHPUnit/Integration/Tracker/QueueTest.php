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
        $this->assertEquals(array(), $this->buildNumRequests(0));

        $expected = array(
            new Request(array('idsite' => 1)),
            new Request(array('idsite' => 2))
        );

        $this->assertEquals($expected, $this->buildNumRequests(2));

        $expected = array(
            new Request(array('idsite' => 1)),
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 3)),
            new Request(array('idsite' => 4)),
            new Request(array('idsite' => 5)),
        );

        $this->assertEquals($expected, $this->buildNumRequests(5));
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

    public function test_addRequests_ShouldNotAddAnything_IfNoRequestsGiven()
    {
        $this->queue->addRequests(array());
        $this->assertEquals(array(), $this->queue->getRequestsToProcess());
    }

    public function test_addRequests_ShouldBeAble_ToAddOneRequest()
    {
        $this->queue->addRequests($this->buildNumRequests(1));
        $this->assertEquals(array(new Request(array('idsite' => 1))), $this->queue->getRequestsToProcess());
    }

    public function test_addRequests_ShouldBeAble_ToAddManyRequests()
    {
        $this->queue->addRequests($this->buildNumRequests(2));
        $this->assertCount(2, $this->queue->getRequestsToProcess());
        $this->assertEquals($this->buildNumRequests(2), $this->queue->getRequestsToProcess());
    }

    public function test_shouldProcess_ShouldReturnValue_WhenQueueIsEmptyOrHasTooLessRequests()
    {
        $this->assertFalse($this->queue->shouldProcess());
    }

    public function test_shouldProcess_ShouldReturnTrue_OnceNumberOfRequestsAreAvailable()
    {
        // 2 < 3 should return false
        $this->queue->addRequests($this->buildNumRequests(2));
        $this->assertFalse($this->queue->shouldProcess());

        // now min number of requests is reached
        $this->queue->addRequests($this->buildNumRequests(1));
        $this->assertTrue($this->queue->shouldProcess());

        // when there are more than 3 requests (5) should still return true
        $this->queue->addRequests($this->buildNumRequests(2));
        $this->assertTrue($this->queue->shouldProcess());
    }

    public function test_shouldProcess_ShouldReturnTrue_AsLongAsThereAreEnoughRequestsInQueue()
    {
        // 5 > 3 so should process
        $this->queue->addRequests($this->buildNumRequests(5));
        $this->assertTrue($this->queue->shouldProcess());

        // should no longer process as now 5 - 3 = 2 requests are in queue
        $this->queue->markRequestsAsProcessed();
        $this->assertFalse($this->queue->shouldProcess());

        // 6 + 2 = 8 which > 3
        $this->queue->addRequests($this->buildNumRequests(6));
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
        $this->queue->addRequests($this->buildNumRequests(2));

        $requests = $this->queue->getRequestsToProcess();
        $expected = $this->buildNumRequests(2);

        $this->assertEquals($expected, $requests);
    }

    public function test_getRequestsToProcess_shouldReturnOnlyTheRequestsThatActuallyNeedToBeProcessed_IfQueueContainsMoreEntries()
    {
        $this->queue->addRequests($this->buildNumRequests(10));

        $requests = $this->queue->getRequestsToProcess();
        $expected = $this->buildNumRequests(3);

        $this->assertEquals($expected, $requests);
    }

    public function test_getRequestsToProcess_shouldReturnRemoveAnyEntriesFromTheQueue()
    {
        $this->queue->addRequests($this->buildNumRequests(5));

        $expected = $this->buildNumRequests(3);

        $this->assertEquals($expected, $this->queue->getRequestsToProcess());
        $this->assertEquals($expected, $this->queue->getRequestsToProcess());
        $this->assertEquals($expected, $this->queue->getRequestsToProcess());
    }

    public function test_markRequestsAsProcessed_ShouldNotFail_IfQueueIsEmpty()
    {
        $this->queue->markRequestsAsProcessed();
        $this->assertEquals(array(), $this->queue->getRequestsToProcess());
    }

    public function test_markRequestsAsProcessed_ShouldRemoveTheConfiguredNumberOfRequests()
    {
        $this->queue->addRequests($this->buildNumRequests(5));

        $expected = $this->buildNumRequests(3);

        $this->assertEquals($expected, $this->queue->getRequestsToProcess());

        $this->queue->markRequestsAsProcessed();

        $expected = array(
            new Request(array('idsite' => 4)),
            new Request(array('idsite' => 5))
        );

        $this->assertEquals($expected, $this->queue->getRequestsToProcess());

        $this->queue->markRequestsAsProcessed();
        $this->assertEquals(array(), $this->queue->getRequestsToProcess());
    }

    private function buildNumRequests($numRequests)
    {
        $requests = array();

        for ($index = 1; $index <= $numRequests; $index++) {
            $requests[] = new Request(array('idsite' => $index));
        }

        return $requests;
    }

}
