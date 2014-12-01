<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;

use Piwik\Plugins\QueuedTracking\Settings;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group QueuedTracking
 * @group SettingsTest
 * @group Plugins
 * @group Tracker
 */
class SettingsTest extends IntegrationTestCase
{
    /**
     * @var Settings
     */
    private $settings;

    public function setUp()
    {
        parent::setUp();

        $this->settings = new Settings();
    }

    public function tearDown()
    {
        parent::tearDown();
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Max 300 characters
     */
    public function test_redisHost_ShouldFail_IfMoreThan300CharctersGiven()
    {
        $this->settings->redisHost->setValue(str_pad('3', 303, '4'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Port has to be at least 1
     */
    public function test_redisPort_ShouldFail_IfPortIsTooLow()
    {
        $this->settings->redisPort->setValue(0);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Port should be max 65535
     */
    public function test_redisPort_ShouldFail_IfPortIsTooHigh()
    {
        $this->settings->redisPort->setValue(65536);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Max 5 characters
     */
    public function test_redisTimeout_ShouldFail_IfTooLong()
    {
        $this->settings->redisTimeout->setValue('333.43');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage should be numeric
     */
    public function test_redisTimeout_ShouldFail_IfNotNumeric()
    {
        $this->settings->redisTimeout->setValue('33d3.43');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Max 100 characters
     */
    public function test_redisPassword_ShouldFail_IfMoreThan100CharctersGiven()
    {
        $this->settings->redisPassword->setValue(str_pad('4', 102, '4'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Connection to Redis failed
     */
    public function test_queueEnabled_ShouldFail_IfEnabledButWrongConnectionDetail()
    {
        $this->settings->redisPort->setValue(6378);
        $this->settings->queueEnabled->setValue(true);
    }

    public function test_queueEnabled_ShouldNotFail_IfEnabledButWrongConnectionDetail()
    {
        $this->settings->redisPort->setValue(6378);
        $this->settings->queueEnabled->setValue(false);

        $this->assertFalse($this->settings->queueEnabled->getValue());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Number should be 1 or higher
     */
    public function test_numRequestsToProcess_ShouldFail_IfTooLow()
    {
        $this->settings->numRequestsToProcess->setValue(0);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Value should be a number
     */
    public function test_numRequestsToProcess_ShouldFail_IfNotNumeric()
    {
        $this->settings->numRequestsToProcess->setValue('33d3.43');
    }

    public function test_redisTimeout_ShouldBeNotUnlimitedByDefault()
    {
        $this->assertSame(0.0, $this->settings->redisTimeout->getValue());
    }

    public function test_redisTimeout_ShouldConvertAValueToFloat()
    {
        $this->settings->redisTimeout->setValue('4.45');
        $this->assertSame(4.45, $this->settings->redisTimeout->getValue());
    }

    public function test_redisPort_ShouldConvertAValueToInt()
    {
        $this->settings->redisPort->setValue('4.45');
        $this->assertSame(4, $this->settings->redisPort->getValue());
    }

    public function test_queueEnabled_ShouldBeDisabledByDefault()
    {
        $this->assertFalse($this->settings->queueEnabled->getValue());
    }

    public function test_queueEnabled_ShouldConvertAnyValueToBool()
    {
        $this->settings->queueEnabled->setValue('4');
        $this->assertTrue($this->settings->queueEnabled->getValue());
    }

    public function test_numRequestsToProcess_ShouldBe50ByDefault()
    {
        $this->assertSame(50, $this->settings->numRequestsToProcess->getValue());
    }

    public function test_numRequestsToProcess_ShouldConvertAnyValueToInteger()
    {
        $this->settings->numRequestsToProcess->setValue('34');
        $this->assertSame(34, $this->settings->numRequestsToProcess->getValue());
    }

    public function test_processDuringTrackingRequest_ShouldBeEnabledByDefault()
    {
        $this->assertTrue($this->settings->processDuringTrackingRequest->getValue());
    }

    public function test_processDuringTrackingRequest_ShouldConvertAnyValueToBoolean()
    {
        $this->settings->processDuringTrackingRequest->setValue('y');
        $this->assertTrue($this->settings->processDuringTrackingRequest->getValue());
    }

}
