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
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\ReportRunner;
use StackNuts\StackGauge\Model\HeartbeatSender;
use Throwable;

/**
 * Verifies the endpoint URL/API key by sending every run the cron jobs send (each hourly and
 * daily slot plus the config sync, via ReportRunner), regardless of the "Enabled" toggle, since
 * an admin testing the connection is likely still mid-setup. Doubles as a manual "sync now".
 */
class TestPing extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    /**
     * @param Action\Context $context
     * @param JsonFactory $jsonFactory
     * @param Config $config
     * @param ReportRunner $reportRunner
     * @param HeartbeatSender $heartbeatSender
     */
    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ReportRunner $reportRunner,
        private readonly HeartbeatSender $heartbeatSender
    ) {
        parent::__construct($context);
    }

    /**
     * Sends a real full report of every cadence tier plus a heartbeat and config-sync.
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

        try {
            $this->heartbeatSender->sendNow();
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (Throwable) {
            // best-effort, don't block the report result on heartbeat
        }

        $sent = 0;
        $failed = [];
        try {
            foreach ($this->reportRunner->all() as $run) {
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
                'message' => __('Success! %1 reports (every hourly and daily slot, plus the config sync) were sent.', $sent)
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
