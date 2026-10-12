<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Console\Command;

use InvalidArgumentException;
use StackNuts\StackGauge\Model\HeartbeatSender;
use StackNuts\StackGauge\Model\ReportRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends (or dry-runs) the same runs the cron jobs send. The target picks which: "all" (default -
 * every hourly and daily slot plus the config sync), "hourly", "daily", one slot such as
 * "hourly:1" or "daily:0", "current" (the hourly slot the clock is in now) or "config".
 * --dry-run builds without sending; --force ignores the "Enabled" toggle.
 */
class SendReportCommand extends Command
{
    private const ARGUMENT_TARGET = 'target';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_FORCE = 'force';

    /**
     * @param ReportRunner $reportRunner
     * @param HeartbeatSender $heartbeatSender
     * @param string|null $name
     */
    public function __construct(
        private readonly ReportRunner $reportRunner,
        private readonly HeartbeatSender $heartbeatSender,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Declares the command name, description, argument and options.
     */
    protected function configure(): void
    {
        $this->setName('stackgauge:send')
            ->setDescription('Send StackGauge reports to the dashboard (all, hourly[:N], daily[:N], current, config)')
            ->addArgument(
                self::ARGUMENT_TARGET,
                InputArgument::OPTIONAL,
                'all | hourly | daily | hourly:N | daily:N | current | config',
                ReportRunner::TARGET_ALL
            )
            ->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Build the payload(s) without sending')
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Send even if StackGauge is disabled in Stores > Configuration > Advanced > StackGauge'
            );

        parent::configure();
    }

    /**
     * Builds or sends each run the target resolves to.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $runs = $this->reportRunner->resolve((string)$input->getArgument(self::ARGUMENT_TARGET));
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        if ($input->getOption(self::OPTION_DRY_RUN)) {
            return $this->dryRun($runs, $output);
        }

        $this->heartbeatSender->sendNow();

        $failed = 0;
        foreach ($runs as $run) {
            if ($this->reportRunner->send($run, (bool)$input->getOption(self::OPTION_FORCE))) {
                $output->writeln(sprintf('<info>%-10s sent</info>', $run->label()));
                continue;
            }
            $failed++;
            $output->writeln(sprintf('<error>%-10s not sent</error>', $run->label()));
        }

        if ($failed > 0) {
            $output->writeln(
                '<error>Check that StackGauge is enabled (or pass --force) and that the endpoint URL / API key are '
                . 'configured. See var/log/stacknuts_stackgauge.log for details.</error>'
            );
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * One run prints its full JSON payload; several print which reporters each would carry.
     *
     * @param \StackNuts\StackGauge\Model\ReportRun[] $runs
     * @param OutputInterface $output
     */
    private function dryRun(array $runs, OutputInterface $output): int
    {
        if (count($runs) === 1) {
            $payload = $this->reportRunner->build($runs[0]);
            $output->writeln((string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        foreach ($runs as $run) {
            $payload = $this->reportRunner->build($run);
            $names = $run->isConfigSync()
                ? [count($payload['metrics'] ?? []) . ' metric(s)']
                : array_keys($payload['reporters'] ?? []);
            $output->writeln(sprintf('%-10s %s', $run->label(), implode(', ', $names)));
        }

        return Command::SUCCESS;
    }
}
