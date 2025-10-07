<?php



namespace Crons;

class BatchedNewEvents extends AbstractCron {
    private \Models\Queue\QueueNewEventsCursor $cursorModel;
    private \Models\Queue\AccountOperationQueue $accountOpQueueModel;
    private \Models\Events $eventsModel;

    public function __construct() {
        parent::__construct();

        $this->cursorModel = new \Models\Queue\QueueNewEventsCursor();
        $this->eventsModel = new \Models\Events();

        $actionType = new \Type\QueueAccountOperationActionType(\Type\QueueAccountOperationActionType::CALCULATE_RISK_SCORE);
        $this->accountOpQueueModel = new \Models\Queue\AccountOperationQueue($actionType);
    }

    public function gatherNewEventsBatch(): void {
        if (!$this->cursorModel->acquireLock() && !$this->cursorModel->unclog()) {
            $this->log('Could not acquire the lock; another cron is probably already working on recently added events.');

            return;
        }

        try {
            $cursor = $this->cursorModel->getCursor();
            $next = $this->cursorModel->getNextCursor($cursor, \Utils\Variables::getNewEventsBatchSize());

            if ($next) {
                $accounts = $this->eventsModel->getDistinctAccounts($cursor, $next);

                $this->accountOpQueueModel->addBatch($accounts);
                $this->cursorModel->updateCursor($next);

                // Log new events cursor to database.
                \Utils\Logger::log('Updated \'last_event_id\' in \'queue_new_events_cursor\' table to ', $next);

                $this->log(sprintf('Added %s accounts to the risk score queue.', count($accounts)));
            } else {
                $this->log('No new events.');
            }
        } catch (\Throwable $e) {
            $this->log(sprintf('Batched new events error %s.', $e->getMessage()));
        } finally {
            $this->cursorModel->releaseLock();
        }
    }
}
