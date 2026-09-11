<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\Clock;

final class CustomerSignalsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly Clock $clock
    ) {
    }

    public function getName(): string
    {
        return 'customers';
    }

    public function getLabel(): string
    {
        return 'Customer Signals';
    }

    public function getDescription(): string
    {
        return 'Counts of customers and recent activity.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $totalCustomers = $this->customerCollectionFactory->create()->getSize();

        $thirtyDaysAgo = $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s');
        $newCustomers = $this->customerCollectionFactory->create()
            ->addFieldToFilter('created_at', ['gte' => $thirtyDaysAgo])
            ->getSize();

        $orders = $this->orderCollectionFactory->create();
        $orders->addFieldToFilter('created_at', ['gte' => $thirtyDaysAgo]);

        $totalOrders = $orders->getSize();
        $guestOrders = $this->orderCollectionFactory->create()
            ->addFieldToFilter('created_at', ['gte' => $thirtyDaysAgo])
            ->addFieldToFilter('customer_id', ['null' => true])
            ->getSize();

        $loggedInOrders = max(0, $totalOrders - $guestOrders);

        return ['general' => Section::facts('general', 'General', $this->getDescription(), [
            'total_customers' => Field::number('Total customers', $totalCustomers),
            'new_customers_30d' => Field::number('New customers (30d)', $newCustomers),
            'guest_orders_30d' => Field::number('Guest orders (30d)', $guestOrders),
            'logged_in_orders_30d' => Field::number('Logged-in orders (30d)', $loggedInOrders),
        ])];
    }
}
