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

    public function checkIsInstalled()
    {
        if (!class_exists('\Redis', false)) {
            throw new \Exception('Redis is not installed. Please check out https://github.com/nrk/predis');
        }
    }

    public function appendValuesToList($key, $values)
    {
        array_unshift($values, $key);

        $redis = $this->getRedis();

        call_user_func_array(array($redis, 'rPush'), $values);
    }

    public function getFirstXValuesFromList($key, $numValues)
    {
        $redis  = $this->getRedis();
        $values = $redis->lRange($key, 0, $numValues);

        return $values;
    }

    public function removeFirstXValuesFromList($key, $numValues)
    {
        $redis = $this->getRedis();
        $redis->ltrim($key, $numValues, -1);
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

        return $redis->delete($key) > 0;
    }

    public function expire($key, $ttlInSeconds)
    {
        $redis = $this->getRedis();

        return $redis->expire($key, $ttlInSeconds);
    }

    private function getRedis()
    {
        if (is_null($this->redis)) {
            $config = $this->getConfig();

            $this->redis = new \Redis();
            $this->redis->connect($config['host'], $config['port'], $config['timeout']);
        }

        return $this->redis;
    }

    private function getConfig()
    {
        return Config::getInstance()->Redis;
    }
}
