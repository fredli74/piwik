<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Tracker;

use Piwik\Tests\Framework\Fixture;
use Piwik\Tracker\Request;
use Piwik\Tracker\TrackerConfig;
use Piwik\Tracker;
use Piwik\Tracker\Queue\Backend\Redis;
use Piwik\Tracker\Queue;
use Piwik\Tracker\Queue\Processor;
use Piwik\Translate;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
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

    public function setUp()
    {
        parent::setUp();

        Redis::enableTestMode();

        $this->queue = new Queue();
        $this->queue->setNumberOfRequestsToProcessAtSameTime(3);

        $this->processor = new Processor($this->queue);
    }

    public function tearDown()
    {
        Redis::clearDatabase();
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

    public function test_proccess_shouldDoNothing_IfQueueIsEmpty()
    {
        $tracker = $this->processor->process();

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestsLeftInQueue(0);
    }

    public function test_proccess_shouldDoNothing_IfLessThanRequiredRequestsAreInQueue()
    {
        $this->queue->addRequests($this->buildNumRequests(2));

        $tracker = $this->processor->process();

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestsLeftInQueue(2);
    }

    public function test_proccess_shouldProcessOnce_IfExactNumberOfRequiredRequestsAreInQueue()
    {
        $this->queue->addRequests($this->buildNumRequests(3));

        $tracker = $this->processor->process();

        $this->assertSame(3, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestsLeftInQueue(0);
    }

    public function test_proccess_shouldProcessOnlyNumberOfRequiredRequests_IfThereAreMoreRequests()
    {
        $this->queue->addRequests($this->buildNumRequests(5));

        $tracker = $this->processor->process();

        $this->assertSame(3, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestsLeftInQueue(2);
    }

    public function test_proccess_shouldProcessMultipleTimes_IfThereAreManyMoreRequestsThanRequired()
    {
        $this->queue->addRequests($this->buildNumRequests(10));

        $tracker = $this->processor->process();

        $this->assertSame(9, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestsLeftInQueue(1);
    }

    public function test_proccess_shouldNotProcessAnything_IfRecordStatisticsIsDisabled()
    {
        $this->queue->addRequests($this->buildNumRequests(8));

        $record = TrackerConfig::getConfigValue('record_statistics');
        TrackerConfig::setConfigValue('record_statistics', 0);
        $tracker = $this->processor->process();
        TrackerConfig::setConfigValue('record_statistics', $record);

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());

        $this->queue->setNumberOfRequestsToProcessAtSameTime(100); // would otherwise only read max 3 requests
        $this->assertNumberOfRequestsLeftInQueue(8);
    }

    private function assertNumberOfRequestsLeftInQueue($numRequestsLeftInQueue)
    {
        $this->assertCount($numRequestsLeftInQueue, $this->queue->getRequestsToProcess());
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
