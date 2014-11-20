<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Tracker\Queue\Backend;

use Piwik\Tracker;
use Piwik\Translate;
use Piwik\Config;

class Redis
{
    /**
     * @var \Redis
     */
    private $redis;
    private static $testMode = false;

    public function checkIsInstalled()
    {
        if (!class_exists('\Redis', false)) {
            throw new \Exception('Redis is not installed. Please check out https://github.com/nicolasff/phpredis');
        }
    }

    public function appendValuesToList($key, $values)
    {
        $redis = $this->getRedis();

        foreach ($values as $value) {
            $redis->rPush($key, $value);
        }

        // usually we would simply do call_user_func_array(array($redis, 'rPush'), $values); as rpush supports multiple values
        // at once but it seems to be not implemented yet see https://github.com/nicolasff/phpredis/issues/366
        // doing it in one command should be much faster as it requires less tcp communication. Anyway, we currently do
        // not write multiple values at once ... so it is ok!
    }

    public function getFirstXValuesFromList($key, $numValues)
    {
        if ($numValues <= 0) {
            return array();
        }

        $redis  = $this->getRedis();
        $values = $redis->lRange($key, 0, $numValues - 1);

        return $values;
    }

    public function removeFirstXValuesFromList($key, $numValues)
    {
        if ($numValues <= 0) {
            return;
        }

        $redis = $this->getRedis();
        return $redis->ltrim($key, $numValues, -1);
    }

    public function getNumValuesInList($key)
    {
        $redis = $this->getRedis();

        return $redis->lLen($key);
    }

    public function setIfNotExists($key, $value)
    {
        $redis  = $this->getRedis();
        $wasSet = $redis->setnx($key, $value);

        return $wasSet;
    }

    public function delete($key)
    {
        $redis = $this->getRedis();

        return $redis->del($key) > 0;
    }

    public function expire($key, $ttlInSeconds)
    {
        $redis = $this->getRedis();

        return (bool) $redis->expire($key, $ttlInSeconds);
    }

    public function flushAll()
    {
        $this->getRedis()->flushAll();
    }

    private function getRedis()
    {
        if (is_null($this->redis)) {
            $config = $this->getConfig();

            $this->redis = new \Redis();
            $this->redis->connect($config['host'], $config['port'], $config['timeout']);

            if (self::$testMode) {
                $this->redis->select(2);
            }
        }

        return $this->redis;
    }

    private function getConfig()
    {
        return Config::getInstance()->Redis;
    }

    public static function enableTestMode()
    {
        self::$testMode = true;
    }

    public static function clearDatabase()
    {
        self::enableTestMode(); // prevent deletion of production db accidentally
        $redis = new Redis();
        $redis->flushAll();
    }
}
