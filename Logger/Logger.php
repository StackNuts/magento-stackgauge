<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Logger;

use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\System\Config\Source\LogLevel;

/**
 * Gates every call against the admin-configured "Log Level" before writing to
 * var/log/stacknuts_stackgauge.log, so an hourly/5-minute cron doesn't fill that file with
 * routine Info lines on stores that don't want them. Wraps the actual Monolog writer
 * (Logger\Handler via the "writer" virtualType in etc/di.xml) rather than extending it,
 * since the level check has to happen before a message ever reaches Monolog's own handler.
 */
class Logger implements LoggerInterface
{
    /**
     * @param LoggerInterface $writer
     * @param Config $config
     */
    public function __construct(
        private readonly LoggerInterface $writer,
        private readonly Config $config
    ) {
    }

    /**
     * Logs an EMERGENCY-level message, subject to the configured log level.
     *
     * @param string|\Stringable $message
     * @param array $context
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::EMERGENCY, $message, $context);
    }

    /**
     * Logs an ALERT-level message, subject to the configured log level.
     *
     * @param string|\Stringable $message
     * @param array $context
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::ALERT, $message, $context);
    }

    /**
     * Logs a CRITICAL-level message, subject to the configured log level.
     *
     * @param string|\Stringable $message
     * @param array $context
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::CRITICAL, $message, $context);
    }

    /**
     * Logs an ERROR-level message, subject to the configured log level.
     *
     * @param string|\Stringable $message
     * @param array $context
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::ERROR, $message, $context);
    }

    /**
     * Logs a WARNING-level message, subject to the configured log level.
     *
     * @param string|\Stringable $message
     * @param array $context
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::WARNING, $message, $context);
    }

    /**
     * Logs a NOTICE-level message, subject to the configured log level.
     *
     * @param string|\Stringable $message
     * @param array $context
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::NOTICE, $message, $context);
    }

    /**
     * Logs an INFO-level message, subject to the configured log level.
     *
     * @param string|\Stringable $message
     * @param array $context
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::INFO, $message, $context);
    }

    /**
     * Logs a DEBUG-level message, subject to the configured log level.
     *
     * @param string|\Stringable $message
     * @param array $context
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::DEBUG, $message, $context);
    }

    /**
     * Logs a message at an arbitrary level, subject to the configured log level.
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $numericLevel = $this->normaliseLevel($level);
        if ($numericLevel === null || !$this->shouldLog($numericLevel)) {
            return;
        }

        $this->writer->log($numericLevel, $message, $context);
    }

    /**
     * Resolves a PSR-3 string level ("info"), a Monolog level enum or an int to Monolog's int
     * level; null for anything unrecognised.
     *
     * @param mixed $level
     */
    private function normaliseLevel(mixed $level): ?int
    {
        if (is_int($level)) {
            return $level;
        }

        if (!is_string($level) && !$level instanceof \Monolog\Level) {
            return null;
        }

        try {
            return MonologLogger::toMonologLevel($level)->value;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the admin-configured log level allows $level through.
     *
     * @param int $level
     */
    private function shouldLog(int $level): bool
    {
        $configuredLevel = $this->config->getLogLevel();

        return $configuredLevel !== LogLevel::LEVEL_OFF && $level >= $configuredLevel;
    }
}
