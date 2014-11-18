<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tracker\BulkTracking;

use Piwik\Tracker;
use Piwik\Tracker\TrackerConfig;
use Exception;

class Handler extends Tracker\Handler
{
    private $transactionId = null;

    public function onStartTrackRequests(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
        if ($this->isTransactionSupported()) {
            $this->beginTransaction();
        }
    }

    public function onAllRequestsTracked(Tracker $tracker, Tracker\Requests $requests, Tracker\Response $response)
    {
        $this->commitTransaction();

        // Do not run schedule task if we are importing logs or doing custom tracking (as it could slow down)
    }

    public function onException(Tracker $tracker, Tracker\Response $response, Exception $e)
    {
        $this->rollbackTransaction();
        parent::onException($tracker, $response, $e);
    }

    protected function beginTransaction()
    {
        if (!empty($this->transactionId)) {
            return;
        }

        $this->transactionId = Tracker::getDatabase()->beginTransaction();
    }

    private function commitTransaction()
    {
        if (empty($this->transactionId)) {
            return;
        }
        Tracker::getDatabase()->commit($this->transactionId);
        $this->transactionId = null;
    }

    protected function rollbackTransaction()
    {
        if (empty($this->transactionId)) {
            return;
        }
        Tracker::getDatabase()->rollback($this->transactionId);
    }

    /**
     * @return bool
     */
    protected function isTransactionSupported()
    {
        return (bool) TrackerConfig::getConfigValue('bulk_requests_use_transaction');
    }

}
