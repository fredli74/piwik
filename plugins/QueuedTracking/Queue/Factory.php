<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue;

use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Settings;

/**
 * This class represents a page view, tracking URL, page title and generation time.
 */
class Factory
{
    private static $settings;

    public static function makeBackend()
    {
        $settings = self::getSettings();

        return self::makeBackendFromSettings($settings);
    }

    public static function getSettings()
    {
        if (is_null(self::$settings)) {
            self::$settings = new Settings(); // for performance reasons... saves as a few ms per request not having to init each setting all the time
        }

        return self::$settings;
    }

    public static function makeQueue(Backend $backend)
    {
        $settings = self::getSettings();

        return self::makeQueueFromSettings($settings, $backend);
    }

    public static function makeRedisTestBackend()
    {
        $redis = new Queue\Backend\Redis();
        $redis->setConfig('127.0.0.1', 6379, 0.0, null);
        $redis->setDatabase(999);

        return $redis;
    }

    private static function makeQueueFromSettings(Settings $settings, Backend $backend)
    {
        $queue = new Queue($backend);
        $queue->setNumberOfRequestsToProcessAtSameTime($settings->numRequestsToProcess->getValue());

        return $queue;
    }

    private static function makeBackendFromSettings(Settings $settings)
    {
        $host     = $settings->redisHost->getValue();
        $port     = $settings->redisPort->getValue();
        $timeout  = $settings->redisTimeout->getValue();
        $password = $settings->redisPassword->getValue();

        $redis = new Queue\Backend\Redis();
        $redis->setConfig($host, $port, $timeout, $password);

        return $redis;
    }

}
