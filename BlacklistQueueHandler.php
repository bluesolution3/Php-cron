<?php


namespace Crons;

class BlacklistQueueHandler extends AbstractQueueCron {
    public function __construct() {
        parent::__construct();

        $actionType = new \Type\QueueAccountOperationActionType(\Type\QueueAccountOperationActionType::BLACKLIST);
        $this->accountOpQueueModel = new \Models\Queue\AccountOperationQueue($actionType);
    }

    public function processQueue(): void {
        if ($this->accountOpQueueModel->isExecuting() && !$this->accountOpQueueModel->unclog()) {
            $this->log('Blacklist queue is already being executed by another cron job.');

            return;
        }

        $this->processItems($this->accountOpQueueModel);
    }

    protected function processItem(array $item): void {
        $fraud = true;

        $dataController = new \Controllers\Admin\User\Data();
        $items = $dataController->setFraudFlag(
            $item['event_account'],
            $fraud,
            $item['key'],
        );

        $model = new \Models\User();
        $username = $model->getUser($item['event_account'], $item['key'])['userid'] ?? '';

        $logger = new \Log('blacklist.log');
        $logger->write('[BlacklistQueue] ' . $username . ' added to blacklist.');

        $model = new \Models\ApiKeys();
        $model->getKeyById($item['key']);

        if (!$model->skip_blacklist_sync && $model->token) {
            $user = new \Models\User();
            $userEmail = $user->getUser($item['event_account'], $item['key'])['email'] ?? null;

            if ($userEmail !== null) {
                $hashes = $this->getHashes($items, $userEmail);
                $errorMessage = $this->sendBlacklistReportPostRequest($hashes, $model->token);
                if (strlen($errorMessage) > 0) {
                    // Log error to database
                    \Utils\Logger::log('Fraud enrichment API curl error', $errorMessage);
                    $this->log('Fraud enrichment API curl error logged to database.');
                }
            }
        }
    }

    /**
     * @param array<array{type: string, value: string}> $items
     */
    private function getHashes(array $items, string $userEmail): array {
        $userHash = hash('sha256', $userEmail);

        return array_map(function ($item) use ($userHash) {
            return [
                'type'  => $item['type'],
                'value' => hash('sha256', $item['value']),
                'id'    => $userHash,
            ];
        }, $items);
    }

    /**
     * @param array<array{type: string, value: string}> $hashes
     */
    private function sendBlacklistReportPostRequest(array $hashes, string $enrichmentKey): string {
        $postFields = [
            'data' => $hashes,
        ];
        $options = [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $enrichmentKey,
                'User-Agent: ' . $this->f3->get('USER_AGENT'),
            ],
            'content' => \json_encode($postFields),
        ];

        /** @var array{request: array<string>, body: string, headers: array<string>, engine: string, cached: bool, error: string} $result */
        $result = \Web::instance()->request(
            url: \Utils\Variables::getEnrichtmentApi() . '/global_alert_report',
            options: $options,
        );

        return $result['error'];
    }
}
