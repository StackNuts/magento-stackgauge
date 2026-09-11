<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Cron;

use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\HeartbeatSender;
use Throwable;

/**
 * 5-minute lightweight heartbeat job (see etc/crontab.xml, "stackgauge" cron group). Same
 * never-throw wrapping as SendReport - see that class's docblock for why.
 */
class SendHeartbeat
{
    public function __construct(
        private readonly HeartbeatSender $heartbeatSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->heartbeatSender->send();
        } catch (Throwable $e) {
            $this->logger->critical('StackGauge: SendHeartbeat cron job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
