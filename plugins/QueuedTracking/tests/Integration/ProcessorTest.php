<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;

use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\RequestSet;
use Piwik\Tracker\TrackerConfig;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Translate;

/**
 * @group QueuedTracking
 * @group Queue
 * @group ProcessorTest
 * @group Tracker
 * @group Redis
 */
class ProcessorTest extends IntegrationTestCase
{

    /**
     * @var Processor
     */
    private $processor;

    /**
     * @var Queue
     */
    private $queue;

    /**
     * @var Redis
     */
    private $redis;

    public function setUp()
    {
        parent::setUp();

        $this->redis = $this->createRedisBackend();

        $this->queue = new Queue($this->redis);
        $this->queue->setNumberOfRequestsToProcessAtSameTime(3);

        $this->processor = $this->createProcessor();
    }

    public function tearDown()
    {
        $this->clearRedisDb();
        parent::tearDown();
    }

    public function test_acquireLock_ShouldLockInCaseItIsNotLockedYet()
    {
        $this->assertTrue($this->processor->acquireLock());
        $this->assertFalse($this->processor->acquireLock());

        $this->processor->unlock();

        $this->assertTrue($this->processor->acquireLock());
        $this->assertFalse($this->processor->acquireLock());
    }

    public function test_unlock_anotherProcessShouldNotBeAbleToUnlockALockedCommand()
    {
        $this->assertTrue($this->processor->acquireLock());

        $processor = $this->createProcessor();
        $processor->unlock();

        $this->assertFalse($processor->acquireLock());

        // now unlock the actual process
        $this->processor->unlock();

        // now it is actually unlocked and possible to lock again
        $this->assertTrue($processor->acquireLock());
    }

    public function test_process_shouldDoNothing_IfQueueIsEmpty()
    {
        $tracker = $this->processor->process();

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(0);
    }

    public function test_process_shouldDoNothing_IfLessThanRequiredRequestsAreInQueue()
    {
        $this->addRequestSetsToQueue(2);

        $tracker = $this->processor->process();

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(2);
    }

    public function test_process_shouldProcessOnce_IfExactNumberOfRequiredRequestsAreInQueue()
    {
        $this->addRequestSetsToQueue(3);

        $tracker = $this->lockAndProcess();

        $this->assertSame(3, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(0);
    }

    public function test_process_shouldProcessOnlyNumberOfRequiredRequests_IfThereAreMoreRequests()
    {
        $this->addRequestSetsToQueue(5);

        $tracker = $this->lockAndProcess();

        $this->assertSame(3, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(2);
    }

    public function test_process_shouldProcessMultipleTimes_IfThereAreManyMoreRequestsThanRequired()
    {
        $this->addRequestSetsToQueue(10);

        $tracker = $this->lockAndProcess();

        $this->assertSame(9, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(1);
    }

    public function test_process_shouldNotProcess_IfLockWasNotAcquired()
    {
        $this->addRequestSetsToQueue(10);

        $tracker = $this->processor->process();

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(10);
    }

    public function test_process_shouldNotProcessAnything_IfRecordStatisticsIsDisabled()
    {
        $this->addRequestSetsToQueue(8);

        $record = TrackerConfig::getConfigValue('record_statistics');
        TrackerConfig::setConfigValue('record_statistics', 0);
        $tracker = $this->lockAndProcess();
        TrackerConfig::setConfigValue('record_statistics', $record);

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());

        $this->assertSame(8, $this->queue->getNumberOfRequestSetsInQueue());
    }

    public function test_process_shouldProcessEachBulkRequestsWithinRequest()
    {
        $this->queue->addRequestSet($this->buildRequestSet(1));
        $this->queue->addRequestSet($this->buildRequestSet(2)); // bulk
        $this->queue->addRequestSet($this->buildRequestSet(4)); // bulk
        $this->queue->addRequestSet($this->buildRequestSet(1));
        $this->queue->addRequestSet($this->buildRequestSet(8)); // bulk

        $tracker = $this->lockAndProcess();

        $this->assertSame(7, $tracker->getCountOfLoggedRequests());

        $this->assertNumberOfRequestSetsLeftInQueue(2);
    }

    public function test_process_shouldCallACallbackMethod_IfSet()
    {
        $this->addRequestSetsToQueue(16);

        $called = 0;
        $self   = $this;
        $queue  = $this->queue;

        $this->processor->setOnProcessNewRequestSetCallback(function ($passedQueue, Tracker $tracker) use (&$called, $self, $queue) {
            $self->assertSame($queue, $passedQueue);
            $self->assertTrue($tracker instanceof $tracker);
            $self->assertGreaterThanOrEqual(0, $tracker->getCountOfLoggedRequests());
            $called++;
        });

        $this->lockAndProcess();

        $this->assertSame(5, $called); // 16 / 3 = 5
    }

    private function lockAndProcess()
    {
        $this->assertTrue($this->processor->acquireLock());

        return $this->processor->process();
    }

    private function assertNumberOfRequestSetsLeftInQueue($numRequestsLeftInQueue)
    {
        $this->assertSame($numRequestsLeftInQueue, $this->queue->getNumberOfRequestSetsInQueue());
    }

    private function addRequestSetsToQueue($numRequestSets)
    {
        for ($index = 1; $index <= $numRequestSets; $index++) {
            $this->queue->addRequestSet($this->buildRequestSet(1));
        }
    }

    private function buildRequestSet($numRequests)
    {
        $req = new RequestSet();

        $requests = array();
        for ($index = 1; $index <= $numRequests; $index++) {
            $requests[] = array('idsite' => $index);
        }

        $req->setRequests($requests);

        return $req;
    }

    private function createProcessor()
    {
        return new Processor($this->queue, $this->redis);
    }
}
