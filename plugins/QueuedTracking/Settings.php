<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Common;
use Piwik\Settings\SystemSetting;

/**
 * Defines Settings for QueuedTracking.
 */
class Settings extends \Piwik\Plugin\Settings
{
    /** @var SystemSetting */
    public $redisHost;

    /** @var SystemSetting */
    public $redisPort;

    /** @var SystemSetting */
    public $redisTimeout;

    /** @var SystemSetting */
    public $redisPassword;

    /** @var SystemSetting */
    public $queueEnabled;

    /** @var SystemSetting */
    public $numRequestsToProcess;

    /** @var SystemSetting */
    public $processDuringTrackingRequest;

    protected function init()
    {
        $this->setIntroduction('Here you can specify the settings for queued tracking.');

        $this->createRedisHostSetting();
        $this->createRedisPortSetting();
        $this->createRedisTimeoutSetting();
        $this->createRedisPasswordSetting();
        $this->createQueueEnabledSetting();
        $this->createNumRequestsToProcessSetting();
        $this->createProcessInTrackingRequestSetting();
    }

    private function createRedisHostSetting()
    {
        $this->redisHost = new SystemSetting('redisHost', 'Redis host');
        $this->redisHost->readableByCurrentUser = true;
        $this->redisHost->type  = static::TYPE_STRING;
        $this->redisHost->uiControlType = static::CONTROL_TEXT;
        $this->redisHost->uiControlAttributes = array('size' => 300);
        $this->redisHost->description   = 'Host of Redis server';
        $this->redisHost->defaultValue  = '127.0.0.1';
        $this->redisHost->inlineHelp = 'Max 300 characters are allowed.';
        $this->redisHost->validate = function ($value) {
            if (strlen($value) > 300) {
                throw new \Exception('Redis host should be max 300 characters long');
            }
        };

        $this->addSetting($this->redisHost);
    }

    private function createRedisPortSetting()
    {
        $this->redisPort = new SystemSetting('redisPort', 'Redis port');
        $this->redisPort->readableByCurrentUser = true;
        $this->redisPort->type  = static::TYPE_INT;
        $this->redisPort->uiControlType = static::CONTROL_TEXT;
        $this->redisPort->uiControlAttributes = array('size' => 5);
        $this->redisPort->description   = 'Port to Redis server';
        $this->redisPort->defaultValue  = '6379';
        $this->redisPort->inlineHelp = 'Value should be between 1 and 65535.';
        $this->redisPort->validate = function ($value) {
            if ($value < 1) {
                throw new \Exception('Port has to be at least 1');
            }

            if ($value >= 65535) {
                throw new \Exception('Port should be max 65535');
            }
        };

        $this->addSetting($this->redisPort);
    }

    private function createRedisTimeoutSetting()
    {
        $this->redisTimeout = new SystemSetting('redisTimeout', 'Redis timeout');
        $this->redisTimeout->readableByCurrentUser = true;
        $this->redisTimeout->type  = static::TYPE_FLOAT;
        $this->redisTimeout->uiControlType = static::CONTROL_TEXT;
        $this->redisTimeout->uiControlAttributes = array('size' => 5);
        $this->redisTimeout->description   = 'Redis connection timeout in seconds';
        $this->redisTimeout->inlineHelp    = '"0.0" meaning unlimited.';
        $this->redisTimeout->defaultValue  = '0.0';
        $this->redisTimeout->validate = function ($value) {
            if (strlen($value) > 5) {
                throw new \Exception('Max 5 characters are allowed');
            }

            if (!is_numeric($value)) {
                throw new \Exception('Timeout should be numeric, eg "0.0"');
            }
        };
        $this->redisTimeout->transform = function ($value) {
            $value = (float) $value;

            if (0.0 === $value) {
                return '0.0';
            }

            return Common::forceDotAsSeparatorForDecimalPoint($value);
        };


        $this->addSetting($this->redisTimeout);
    }

    private function createRedisPasswordSetting()
    {
        $this->redisPassword = new SystemSetting('redisPassword', 'Redis password');
        $this->redisPassword->readableByCurrentUser = true;
        $this->redisPassword->type  = static::TYPE_STRING;
        $this->redisPassword->uiControlType = static::CONTROL_PASSWORD;
        $this->redisPassword->uiControlAttributes = array('size' => 100);
        $this->redisPassword->description   = 'An optional password for your Redis instance';
        $this->redisPassword->inlineHelp    = 'Redis can be instructed to require a password before allowing clients to execute commands.';
        $this->redisPassword->defaultValue  = '';
        $this->redisPassword->validate = function ($value) {
            if (strlen($value) > 100) {
                throw new \Exception('Max 100 characters are allowed');
            }
        };

        $this->addSetting($this->redisPassword);
    }

    private function createQueueEnabledSetting()
    {
        $self = $this;
        $this->queueEnabled        = new SystemSetting('queueEnabled', 'Queue enabled');
        $this->queueEnabled->readableByCurrentUser = true;
        $this->queueEnabled->type  = static::TYPE_BOOL;
        $this->queueEnabled->uiControlType = static::CONTROL_CHECKBOX;
        $this->queueEnabled->description   = 'Enable writing all tracking requests into a queue';
        $this->queueEnabled->inlineHelp    = 'If enabled, all tracking requests will be written into a queue. Requires a Redis server and phpredis PHP extension.';
        $this->queueEnabled->defaultValue  = false;
        $this->queueEnabled->validate = function ($value) use ($self) {
            $value = (bool) $value;

            if ($value) {
                $host = $self->redisHost->getValue();
                $port = $self->redisPort->getValue();
                $timeout = $self->redisTimeout->getValue();
                $password = $self->redisPassword->getValue();

                $systemCheck = new SystemCheck();
                $systemCheck->checkRedisIsInstalled();
                $systemCheck->checkConnectionDetails($host, $port, $timeout, $password);
            }
        };

        $this->addSetting($this->queueEnabled);
    }

    private function createNumRequestsToProcessSetting()
    {
        $this->numRequestsToProcess = new SystemSetting('numRequestsToProcess', 'Number of requests in queue to process');
        $this->numRequestsToProcess->readableByCurrentUser = true;
        $this->numRequestsToProcess->type  = static::TYPE_INT;
        $this->numRequestsToProcess->uiControlType = static::CONTROL_TEXT;
        $this->numRequestsToProcess->uiControlAttributes = array('size' => 3);
        $this->numRequestsToProcess->description     = 'Defines how many requests will be picked out of the queue and processed at once';
        $this->numRequestsToProcess->inlineHelp      = 'Enter a number which is >= 1. In case you set the value to 1 it is recommend to disable "Process during tracking request" and use a console command instead to process the requests. You might want to adjust this number eg to the number of tracking requests you get per 10 seconds on average.';
        $this->numRequestsToProcess->defaultValue    = '50';
        $this->numRequestsToProcess->validate = function ($value, $setting) {

            if ((int) $value < 1) {
                throw new \Exception('Value is invalid ' . $value);
            }

            if (!is_numeric($value)) {
                throw new \Exception('Value should be a number');
            }
        };

        $this->addSetting($this->numRequestsToProcess);
    }

    private function createProcessInTrackingRequestSetting()
    {
        $this->processDuringTrackingRequest = new SystemSetting('processDuringTrackingRequest', 'Process during tracking request');
        $this->processDuringTrackingRequest->readableByCurrentUser = true;
        $this->processDuringTrackingRequest->type = static::TYPE_BOOL;
        $this->processDuringTrackingRequest->uiControlType = static::CONTROL_CHECKBOX;
        $this->processDuringTrackingRequest->inlineHelp = 'If enabled, we will process all requests within a queue during a normal tracking request once there are enough requests in the queue. This will not slow down the tracking request. If disabled, you have to setup a cronjob that executes the "./console queuedtracking:process" console command eg every minute to process the queue.';
        $this->processDuringTrackingRequest->defaultValue = true;

        $this->addSetting($this->processDuringTrackingRequest);
    }

}
