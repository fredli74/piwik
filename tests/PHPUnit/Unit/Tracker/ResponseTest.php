<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Unit\Tracker;

use Piwik\Common;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tracker\Response;
use Piwik\Tests\Framework\Mock\Tracker;
use Piwik\Tests\Framework\TestCase\UnitTestCase;
use Exception;

class TestResponse extends Response {

    protected function logExceptionToErrorLog()
    {
        // prevent console from outputting the error_log message
    }

    public function getMessageFromException($e)
    {
        return parent::getMessageFromException($e);
    }
}

/**
 * @group BulkTracking
 * @group ResponseTest
 * @group Plugins
 */
class ResponseTest extends UnitTestCase
{
    /**
     * @var TestResponse
     */
    private $response;

    public function setUp()
    {
        parent::setUp();
        $this->response = new TestResponse();
    }

    public function test_outputException_shouldAlwaysOutputApiResponse_IfDebugModeIsDisabled()
    {
        ob_start();

        $this->response->outputException($this->getTracker(), new Exception('My Custom Message'), 400);

        Fixture::checkResponse(ob_get_clean());
    }

    public function test_outputException_shouldOutputDebugMessageIfEnabled()
    {
        ob_start();

        $tracker = $this->getTracker();
        $tracker->enableDebugMode();

        $this->response->outputException($tracker, new Exception('My Custom Message'), 400);

        $content = ob_get_clean();

        $this->assertContains('<title>Piwik &rsaquo; Error</title>', $content);
        $this->assertContains('<p>My Custom Message', $content);
    }

    public function test_outputResponse_shouldOutputStandardApiResponse()
    {
        ob_start();

        $this->response->outputResponse($this->getTracker());

        Fixture::checkResponse(ob_get_clean());
    }

    public function test_outputResponse_shouldNotOutputApiResponse_IfDebugModeIsEnabled_AsWePrintOtherStuff()
    {
        ob_start();

        $tracker = $this->getTracker();
        $tracker->enableDebugMode();
        $this->response->outputResponse($tracker);

        $this->assertEquals('', ob_get_clean());
    }

    public function test_outputResponse_shouldNotOutputApiResponse_IfSomethingWasPrintedUpfront()
    {
        ob_start();

        echo 5;
        $this->response->outputResponse($this->getTracker());

        $content = ob_get_clean();

        $this->assertEquals('5', $content);
    }

    public function test_outputResponse_shouldOutputNoResponse_If204HeaderIsRequested()
    {
        ob_start();

        $_GET['send_image'] = '0';
        $this->response->outputResponse($this->getTracker());
        unset($_GET['send_image']);

        $this->assertEquals('', ob_get_clean());
    }

    public function test_outputResponse_shouldOutputPiwikMessage_InCaseNothingWasTracked()
    {
        ob_start();

        $tracker = $this->getTracker();
        $tracker->setCountOfLoggedRequests(0);
        $this->response->outputResponse($tracker);

        $this->assertEquals("<a href='/'>Piwik</a> is a free/libre web <a href='http://piwik.org'>analytics</a> that lets you keep control of your data.", ob_get_clean());
    }

    public function test_getMessageFromException_ShouldNotOutputAnyDetails_IfErrorContainsDbCredentials()
    {
        $message = $this->response->getMessageFromException(new Exception('Test Message', 1044));
        $this->assertStringStartsWith("Error while connecting to the Piwik database", $message);

        $message = $this->response->getMessageFromException(new Exception('Test Message', 42000));
        $this->assertStringStartsWith("Error while connecting to the Piwik database", $message);
    }

    public function test_getMessageFromException_ShouldReturnMessageAndTrace_InCaseIsCli()
    {
        $message = $this->response->getMessageFromException(new Exception('Test Message', 8150));
        $this->assertStringStartsWith("Test Message\n#0 [internal function]", $message);
    }

    public function test_getMessageFromException_ShouldOnlyReturnMessage_InCaseIsNotCli()
    {
        Common::$isCliMode = false;
        $message = $this->response->getMessageFromException(new Exception('Test Message', 8150));
        Common::$isCliMode = true;

        $this->assertStringStartsWith("Test Message", $message);
    }

    public function test_outputResponse_shouldOutputApiResponse_IfTrackerIsDisabled()
    {
        ob_start();

        $tracker = $this->getTracker();
        $tracker->setCountOfLoggedRequests(0);
        $tracker->disableShouldRecordStatistics();
        $this->response->outputResponse($tracker);

        Fixture::checkResponse(ob_get_clean());
    }

    private function getTracker()
    {
        $tracker = new Tracker();
        $tracker->setCountOfLoggedRequests(5);
        return $tracker;
    }

}
