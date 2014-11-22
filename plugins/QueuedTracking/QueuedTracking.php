<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Plugins\QueuedTracking\Tracker\Handler;

class QueuedTracking extends \Piwik\Plugin
{
    /**
     * @see Piwik\Plugin::getListHooksRegistered
     */
    public function getListHooksRegistered()
    {
        return array(
            'Tracker.newHandler' => 'replaceHandlerIfQueueIsEnabled'
        );
    }

    public function replaceHandlerIfQueueIsEnabled(&$handler)
    {
        $queue = new Queue();

        if ($queue->isEnabled()) {
            $handler = new Handler();
        }
    }
}
