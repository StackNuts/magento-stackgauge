<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\Amqp\Config as AmqpConfig;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfigInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DataSectionTrait;
use Throwable;

/**
 * Per-queue depth for every queue routed through the "amqp" connection - via the AMQP
 * protocol's own passive queue_declare (reusing Magento's existing Amqp\Config connection)
 * rather than RabbitMQ's Management HTTP API, which would need a third credential set.
 *
 * "Configured" is checked first: most Community Edition sites never set up RabbitMQ (queues
 * fall back to the "db" connection), so this reporter skips attempting a doomed connection.
 */
class RabbitMqReporter implements ReporterInterface, DeclaresSectionInterface
{
    use DataSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const AMQP_CONNECTION = 'amqp';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly TopologyConfigInterface $topologyConfig,
        private readonly AmqpConfig $amqpConfig
    ) {
    }

    public function getName(): string
    {
        return 'rabbitmq';
    }

    public function getLabel(): string
    {
        return 'RabbitMQ';
    }

    public function getDescription(): string
    {
        return 'Per-queue message/consumer count for every queue routed through the "amqp" connection, if configured.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        if (!$this->deploymentConfig->get('queue/amqp/host')) {
            return $this->result(false, null, []);
        }

        try {
            // Cheapest proof the broker is reachable before walking every queue - a channel
            // is required either way, so this isn't an extra round trip.
            $this->amqpConfig->getChannel();
        } catch (Throwable) {
            return $this->result(true, false, []);
        }

        $queues = [];
        foreach ($this->topologyConfig->getQueues() as $queueConfigItem) {
            if ($queueConfigItem->getConnection() !== self::AMQP_CONNECTION) {
                continue;
            }

            $queues[] = $this->checkQueue($queueConfigItem->getName());
        }

        return $this->result(true, true, $queues);
    }

    /**
     * @param list<ArrayField> $queues
     * @return array<string, \StackNuts\StackGauge\Api\Section\SectionInterface>
     */
    private function result(bool $configured, ?bool $reachable, array $queues): array
    {
        $generalFields = ['configured' => Field::bool('Configured', $configured)];
        if ($reachable !== null) {
            $generalFields['reachable'] = Field::bool('Reachable', $reachable, criticalWhen: false);
        }

        return [
            'general' => Section::facts('general', 'General', '', $generalFields),
            'queues' => Section::table('queues', 'Queues', 'Per-queue message/consumer count.', $queues),
        ];
    }

    private function checkQueue(string $name): ArrayField
    {
        try {
            // A queue declared in Magento's topology may not exist on the broker yet if no
            // consumer has ever run - NOT_FOUND is a normal outcome here, not a failure.
            [, $messageCount, $consumerCount] = $this->amqpConfig->getChannel()->queue_declare($name, true);

            return $this->queueField($name, true, $messageCount, $consumerCount);
        } catch (Throwable) {
            // A failed passive declare (e.g. NOT_FOUND) closes the channel at the protocol
            // level - getChannel() transparently reconnects on its next call, so the next
            // queue in the loop isn't affected.
            return $this->queueField($name, false, 0, 0);
        }
    }

    private function queueField(string $name, bool $exists, int $messages, int $consumers): ArrayField
    {
        return Field::array($name, [
            'name' => Field::varchar('Name', $name),
            // Not criticalWhen:false - NOT_FOUND is a normal outcome for a queue that's
            // never had a consumer run yet (see checkQueue()), not a health signal.
            'exists' => Field::bool('Exists', $exists),
            'messages' => Field::number('Messages', $messages),
            'consumers' => Field::number('Consumers', $consumers),
        ]);
    }
}
