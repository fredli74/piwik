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

    public function popValues($key, $values)
    {
        array_unshift($values, $key);

        $redis = $this->getRedis();

        call_user_func_array(array($redis, 'rPush'), $values);
    }

    public function shiftValues($key, $stop)
    {
        $redis  = $this->getRedis();
        $values = $redis->lRange($key, 0, $stop);
        $redis->ltrim($key, $stop - 1, -1);

        return $values;
    }

    public function getNumValues($key)
    {
        $redis = $this->getRedis();

        return $redis->lLen($key);
    }

    public function save($key, $value, $ttlInSeconds)
    {
        $redis = $this->getRedis();

        if ($ttlInSeconds > 0) {
            return $redis->setex($key, $ttlInSeconds, $value);
        }

        return $redis->set($key, $value);
    }

    public function delete($key)
    {
        $redis = $this->getRedis();

        return $redis->delete($key) > 0;
    }

    public function exists($key)
    {
        $redis = $this->getRedis();

        return $redis->exists($key);
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
