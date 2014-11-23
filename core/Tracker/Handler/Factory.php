<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tracker\Handler;

use Exception;
use Piwik\Piwik;
use Piwik\Tracker\Handler;

class Factory
{
    public static function make()
    {
        $handler = null;

        Piwik::postEvent('Tracker.newHandler', array(&$handler));

        if (is_null($handler)) {
            $handler = new Handler();
        } elseif (!($handler instanceof Handler)) {
            throw new Exception("The Handler object set in the plugin must be an instance of Piwik\\Tracker\\Handler");
        }

        return $handler;
    }

}
