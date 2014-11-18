<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tracker\Queue;

use Piwik\Tracker;
use Piwik\Tracker\Queue;

class Handler extends Tracker\Handler
{
    /**
     * @var Queue
     */
    private $queue;

    public function __construct($queue)
    {
        $this->queue = $queue;
    }

    public function process(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
        $this->queue->popRequests($requests->getRequests(), $_SERVER);

        $processor = new Processor($this->queue);
        if ($processor->shouldProcess()) {

            set_time_limit(0);
            $processor->process();
            $processor->finishProcess();
        }

        return false;
    }

}
