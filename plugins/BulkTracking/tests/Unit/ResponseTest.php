<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\BulkTracking\tests\Unit;

use Piwik\Plugins\BulkTracking\Tracker\Response;
use Piwik\Tests\Framework\Mock\Tracker;
use Piwik\Tests\Framework\TestCase\UnitTestCase;
use Exception;

class TestResponse extends Response {

    protected function logExceptionToErrorLog()
    {
        // prevent console from outputting the error_log message
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

    public function test_outputException_shouldOutputBulkResponse()
    {
        ob_start();

        $tracker = new Tracker();
        $tracker->setCountOfLoggedRequests(5);

        $this->response->outputException($tracker, new Exception('My Custom Message'), 400);

        $content = ob_get_clean();

        $this->assertEquals('{"status":"error","tracked":5}', $content);
    }

    public function test_outputException_shouldOutputDebugMessageIfEnabled()
    {
        ob_start();

        $tracker = new Tracker();
        $tracker->setCountOfLoggedRequests(5);
        $tracker->enableDebugMode();

        $this->response->outputException($tracker, new Exception('My Custom Message'), 400);

        $content = ob_get_clean();

        $this->assertStringStartsWith('{"status":"error","tracked":5,"message":"My Custom Message\n', $content);
    }

    public function test_outputResponse_shouldOutputBulkResponse()
    {
        ob_start();

        $tracker = new Tracker();
        $tracker->setCountOfLoggedRequests(5);

        $this->response->outputResponse($tracker);

        $content = ob_get_clean();

        $this->assertEquals('{"status":"success","tracked":5}', $content);
    }

}
