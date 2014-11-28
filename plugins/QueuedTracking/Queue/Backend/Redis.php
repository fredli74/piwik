<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking\Queue\Backend;

use Piwik\Log;
use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Tracker;
use Piwik\Translate;

class Redis implements Backend
{
    /**
     * @var \Redis
     */
    private $redis;
    private $host;
    private $port;
    private $timeout;
    private $password;

    /**
     * @var int
     */
    private $database;

    public function testConnection()
    {
        try {
            $this->connectIfNeeded();
            return 'TEST' === $this->redis->echo('TEST');

        } catch (\Exception $e) {
            Log::debug($e->getMessage());
        }

        return false;
    }

    public function appendValuesToList($key, $values)
    {
        $this->connectIfNeeded();

        foreach ($values as $value) {
            $this->redis->rPush($key, $value);
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

        $this->connectIfNeeded();
        $values = $this->redis->lRange($key, 0, $numValues - 1);

        return $values;
    }

    public function removeFirstXValuesFromList($key, $numValues)
    {
        if ($numValues <= 0) {
            return;
        }

        $this->connectIfNeeded();
        $this->redis->ltrim($key, $numValues, -1);
    }

    public function getNumValuesInList($key)
    {
        $this->connectIfNeeded();

        return $this->redis->lLen($key);
    }

    public function setIfNotExists($key, $value)
    {
        $this->connectIfNeeded();
        $wasSet = $this->redis->setnx($key, $value);

        return $wasSet;
    }

    public function delete($key)
    {
        $this->connectIfNeeded();

        return $this->redis->del($key) > 0;
    }

    public function expire($key, $ttlInSeconds)
    {
        $this->connectIfNeeded();

        return (bool) $this->redis->expire($key, $ttlInSeconds);
    }

    /**
     * @internal
     */
    public function flushAll()
    {
        $this->connectIfNeeded();
        $this->redis->flushAll();
    }

    private function connectIfNeeded()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }
    }

    private function connect()
    {
        $this->redis = new \Redis();
        $success = $this->redis->connect($this->host, $this->port, $this->timeout);

        if ($success && !empty($this->password)) {
            $success = $this->redis->auth($this->password);
        }

        if (!empty($this->database)) {
            $this->redis->select($this->database);
        }

        return $success;
    }

    public function setConfig($host, $port, $timeout, $password)
    {
        $this->disconnect();

        $this->host = $host;
        $this->port = $port;
        $this->timeout  = $timeout;
        $this->password = $password;
    }

    private function disconnect()
    {
        if ($this->isConnected()) {
            $this->redis->close();
        }

        $this->redis = null;
    }

    private function isConnected()
    {
        return !is_null($this->redis);
    }

    public function setDatabase($database)
    {
        $this->database = (int) $database;
    }
}
