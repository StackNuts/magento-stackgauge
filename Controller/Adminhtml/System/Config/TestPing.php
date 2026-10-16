<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Lock\LockManagerInterface;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\ReportRunner;
use StackNuts\StackGauge\Model\HeartbeatSender;
use Throwable;

/**
 * Verifies the endpoint URL/API key by sending a heartbeat, the config sync and the hourly slot
 * the clock is in now (via ReportRunner), regardless of the "Enabled" toggle, since an admin
 * testing the connection is likely still mid-setup. Deliberately not every slot of both tiers
 * (that was seven POSTs after collecting every reporter, enough to hit gateway timeouts); the
 * full set is `bin/magento stackgauge:send`. A lock stops a double click or two admins from
 * running it at once.
 */
class TestPing extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'StackNuts_StackGauge::config';
    public const LOCK_NAME = 'stacknuts_stackgauge_test_ping';
    private const LOCK_TIMEOUT_SECONDS = 0;

    /**
     * @param Action\Context $context
     * @param JsonFactory $jsonFactory
     * @param Config $config
     * @param ReportRunner $reportRunner
     * @param HeartbeatSender $heartbeatSender
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ReportRunner $reportRunner,
        private readonly HeartbeatSender $heartbeatSender,
        private readonly LockManagerInterface $lockManager
    ) {
        parent::__construct($context);
    }

    /**
     * Sends a heartbeat, the config sync and the current hourly slot.
     *
     * Reports whether the dashboard accepted it.
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->getEndpointUrl() || !$this->config->getApiKey()) {
            return $result->setData([
                'success' => false,
                'message' => __('Enter and save both the Dashboard Endpoint URL and API Key first, then test.')->render(),
            ]);
        }

        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS)) {
            return $result->setData([
                'success' => false,
                'message' => __('A test is already running. Wait for it to finish, then try again.')->render(),
            ]);
        }

        try {
            return $this->runTest($result);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Does the sends once the lock is held.
     *
     * @param Json $result
     */
    private function runTest(Json $result): Json
    {
        try {
            $this->heartbeatSender->sendNow();
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (Throwable) {
            // best-effort, don't block the report result on heartbeat
        }

        $sent = 0;
        $failed = [];
        try {
            $runs = [
                ...$this->reportRunner->resolve(ReportRunner::TARGET_CURRENT),
                ...$this->reportRunner->resolve('config'),
            ];
            foreach ($runs as $run) {
                if ($this->reportRunner->send($run, true)) {
                    $sent++;
                } else {
                    $failed[] = $run->label();
                }
            }
        } catch (Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => __('Sending the test report threw an error: %1', $e->getMessage())->render(),
            ]);
        }

        if ($failed === []) {
            return $result->setData([
                'success' => true,
                'message' => __('Success! A heartbeat, the config sync and the current hourly report (%1 reports) were sent.', $sent)
                    ->render(),
            ]);
        }

        return $result->setData([
            'success' => false,
            'message' => __(
                'The dashboard did not accept: %1. Check var/log/stacknuts_stackgauge.log for details.',
                implode(', ', $failed)
            )->render(),
        ]);
    }
}
