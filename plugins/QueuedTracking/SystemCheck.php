<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;
use Piwik\Tracker;
use Piwik\Translate;

class SystemCheck
{
    public function checkRedisIsInstalled()
    {
        if (!class_exists('\Redis', false)) {
            throw new \Exception('Redis is not installed. Please check out https://github.com/nicolasff/phpredis');
        }

        if (!extension_loaded('redis')) {
            throw new \Exception('The phpredis extension is needed in order to activate the queue. Please check out https://github.com/nicolasff/phpredis');
        }
    }

    public function checkConnectionDetails($host, $port, $timeout)
    {
        $redis = new Redis();
        $redis->setConfig($host, $port, $timeout);

        if (!$redis->connect()) {
            throw new \Exception('Connection to Redis failed. Please verify Redis host and port');
        };
    }

}
