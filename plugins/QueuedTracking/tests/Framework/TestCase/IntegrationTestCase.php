<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Framework\TestCase;

use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\QueuedTracking;

/**
 * @group QueuedTracking
 * @group Redis
 */
class IntegrationTestCase extends \Piwik\Tests\Framework\TestCase\IntegrationTestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->configureRedisTestInstance();
    }

    public static function tearDownAfterClass()
    {
        Queue\Factory::clearSettings();

        parent::tearDownAfterClass();
    }

    protected function clearRedisDb()
    {
        $backend = $this->createRedisBackend();
        $backend->flushAll();
    }

    protected function createRedisBackend()
    {
        $this->configureRedisTestInstance();
        return Queue\Factory::makeBackend();
    }

    private function configureRedisTestInstance()
    {
        $queuedTracking = new QueuedTracking();
        $queuedTracking->configureQueueTestBackend();
    }

}
