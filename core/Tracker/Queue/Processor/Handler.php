<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tracker\Queue\Processor;

use Piwik\Tracker;
use Piwik\Tracker\Queue;
use Exception;

class Handler extends Tracker\BulkTracking\Handler
{
    public function __construct()
    {
        $this->setResponse(new Queue\Response());
    }

    public function onException(Tracker $tracker, Exception $e)
    {
        $this->rollbackTransaction();
        $this->beginTransaction();
        // we do not exit on any failure
    }

}
