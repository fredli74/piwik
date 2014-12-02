<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Unit\Queue\Backend;

use Piwik\Plugins\QueuedTracking\Queue\Processor\Handler;
use Piwik\Tests\Framework\Mock\Tracker;
use Piwik\Tests\Framework\Mock\Tracker\Db;
use Piwik\Tests\Framework\TestCase\UnitTestCase;

class TestHandler extends Handler {
    private $handlerDb;

    public function getDb()
    {
        return $this->handlerDb;
    }

    public function setDb($db)
    {
        $this->handlerDb = $db;
    }

    public function setHasError()
    {
        $this->hasError = true;
    }

    public function getTransactionId()
    {
        return $this->transactionId;
    }
}

/**
 * @group QueuedTracking
 * @group HandlerTest
 * @group Plugins
 */
class HandlerTest extends UnitTestCase
{
    /**
     * @var TestHandler
     */
    private $handler;

    /**
     * @var Tracker
     */
    private $tracker;

    /**
     * @var Db
     */
    private $db;

    private $transactionId = 'my4929transactionid';

    public function setUp()
    {
        parent::setUp();
        $this->handler = new TestHandler();
        $this->tracker = new Tracker();
        $this->db      = new Db(array());
        $this->handler->setDb($this->db);
    }

    public function test_init_ShouldStartADatabaseTransaction()
    {
        $this->assertFalse($this->db->beganTransaction);

        $this->handler->init($this->tracker);

        $this->assertEquals($this->transactionId, $this->handler->getTransactionId());
        $this->assertTrue($this->db->beganTransaction);
    }

    public function test_finish_ShouldCommitTransaction_IfThereWasNoError()
    {
        $this->handler->init($this->tracker);

        $this->handler->finish($this->tracker);

        $this->assertEquals($this->transactionId, $this->db->commitTransactionId);
        $this->assertFalse($this->db->rollbackTransactionId);
    }

    public function test_forceARollback_ShouldRollbackTransaction_InFinish()
    {
        $this->handler->init($this->tracker);
        $this->handler->forceARollback();

        $this->handler->finish($this->tracker);

        $this->assertEquals($this->transactionId, $this->db->rollbackTransactionId);
        $this->assertFalse($this->db->commitTransactionId);
    }

    public function test_onException_ShouldForceARollbackTransaction()
    {
        $this->handler->init($this->tracker);
        $this->handler->onException(new Tracker\RequestSet(), new \Exception('test'));

        $this->handler->finish($this->tracker);

        $this->assertEquals($this->transactionId, $this->db->rollbackTransactionId);
        $this->assertFalse($this->db->commitTransactionId);
    }

}
