<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Console\Command;

use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Model\ReportSender;
use StackNuts\StackGauge\Model\HeartbeatSender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual verification without needing an admin Test Ping click: --dry-run prints the
 * assembled payload without sending; --force sends even if "Enabled" is off; --config-sync
 * builds/sends the metric-catalog payload instead of a full report; --cadence picks which
 * reporter tier a (non-config-sync) report covers.
 */
class SendReportCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_FORCE = 'force';
    private const OPTION_CONFIG_SYNC = 'config-sync';
    private const OPTION_CADENCE = 'cadence';

    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly HeartbeatSender $heartbeatSender,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('stackgauge:send')
            ->setDescription('Build and send a StackGauge status report')
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Print the assembled payload as JSON without sending it'
            )
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Send even if StackGauge is disabled in Stores > Configuration > Advanced > StackGauge'
            )
            ->addOption(
                self::OPTION_CONFIG_SYNC,
                null,
                InputOption::VALUE_NONE,
                'Build/send the config-sync payload (trackable metric catalog + defaults) instead of a full report'
            )
            ->addOption(
                self::OPTION_CADENCE,
                null,
                InputOption::VALUE_REQUIRED,
                'Which reporter cadence tier to build/send: hourly or daily',
                DeclaresCadenceInterface::CADENCE_HOURLY
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption(self::OPTION_CONFIG_SYNC)) {
            return $this->executeConfigSync($input, $output);
        }

        $cadence = (string)$input->getOption(self::OPTION_CADENCE);

        if ($input->getOption(self::OPTION_DRY_RUN)) {
            $payload = $this->reportSender->buildPayload($cadence);
            $output->writeln((string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }
        $this->heartbeatSender->sendNow();

        $sent = $input->getOption(self::OPTION_FORCE)
            ? $this->reportSender->sendNow($cadence)
            : $this->reportSender->send($cadence);

        if ($sent) {
            $output->writeln('<info>Report sent.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(
            '<error>Report not sent - check that StackGauge is enabled (or pass --force) and that the endpoint '
            . 'URL / API key are configured. See var/log/stacknuts_stackgauge.log for details.</error>'
        );
        return Command::FAILURE;
    }

    private function executeConfigSync(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption(self::OPTION_DRY_RUN)) {
            $payload = $this->reportSender->buildConfigSync();
            $output->writeln((string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $this->heartbeatSender->sendNow();

        $sent = $input->getOption(self::OPTION_FORCE)
            ? $this->reportSender->sendConfigSyncNow()
            : $this->reportSender->sendConfigSync();

        if ($sent) {
            $output->writeln('<info>Config sync sent.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(
            '<error>Config sync not sent - check that StackGauge is enabled (or pass --force) and that the endpoint '
            . 'URL / API key are configured. See var/log/stacknuts_stackgauge.log for details.</error>'
        );
        return Command::FAILURE;
    }
}
