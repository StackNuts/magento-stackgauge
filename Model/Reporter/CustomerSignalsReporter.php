<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\Clock;

/**
 * Customer totals and 30-day activity, as two aggregate queries (customer_entity and
 * sales_order) rather than four collection counts. Guest orders are those flagged
 * customer_is_guest = 1: customer_id IS NULL would also count orders of customers who have
 * since been deleted (their orders keep the row, with the FK nulled).
 *
 * customer_entity has no index on created_at, so the new-customer count is part of the same
 * single pass that produces the total; sales_order.created_at is indexed.
 */
class CustomerSignalsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param ResourceConnection $resourceConnection
     * @param Clock $clock
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the customer-signals reporter.
     */
    public function getName(): string
    {
        return 'customers';
    }

    /**
     * Human-readable label for the customer-signals reporter block.
     */
    public function getLabel(): string
    {
        return 'Customer Signals';
    }

    /**
     * One-line summary of what the customer-signals reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Counts of customers and recent activity.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports total and 30-day-new customer counts, plus a 30-day split of guest vs logged-in orders.
     */
    public function getStatus(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $thirtyDaysAgo = $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s');

        $customers = (array) $connection->fetchRow(
            $connection->select()->from($this->resourceConnection->getTableName('customer_entity'), [
                'total' => new Expression('COUNT(*)'),
                'recent' => new Expression(
                    'COALESCE(SUM(CASE WHEN created_at >= ' . $connection->quote($thirtyDaysAgo) . ' THEN 1 ELSE 0 END), 0)'
                ),
            ])
        );
        $orders = (array) $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName('sales_order'), [
                    'total' => new Expression('COUNT(*)'),
                    'guest' => new Expression('COALESCE(SUM(CASE WHEN customer_is_guest = 1 THEN 1 ELSE 0 END), 0)'),
                ])
                ->where('created_at >= ?', $thirtyDaysAgo)
        );

        $totalCustomers = (int) ($customers['total'] ?? 0);
        $newCustomers = (int) ($customers['recent'] ?? 0);
        $totalOrders = (int) ($orders['total'] ?? 0);
        $guestOrders = (int) ($orders['guest'] ?? 0);
        $loggedInOrders = max(0, $totalOrders - $guestOrders);

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
            'total_customers' => $this->field->number('Total customers', $totalCustomers),
            'new_customers_30d' => $this->field->number('New customers (30d)', $newCustomers),
            'guest_orders_30d' => $this->field->number('Guest orders (30d)', $guestOrders),
            'logged_in_orders_30d' => $this->field->number('Logged-in orders (30d)', $loggedInOrders),
        ])];
    }
}
