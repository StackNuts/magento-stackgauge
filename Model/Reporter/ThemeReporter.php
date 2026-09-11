<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ScopeInterface as AppScopeInterface;
use Magento\Store\Model\ScopeInterface as StoreScopeInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

final class ThemeReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function getName(): string
    {
        return 'themes';
    }

    public function getLabel(): string
    {
        return 'Themes';
    }

    public function getDescription(): string
    {
        return 'Active frontend and admin themes.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $frontend = (string)$this->scopeConfig->getValue('design/theme/theme_id', StoreScopeInterface::SCOPE_STORE);
        $admin = (string)$this->scopeConfig->getValue('design/theme/theme_id', AppScopeInterface::SCOPE_DEFAULT);

        $themes = [
            Field::array('frontend', [
                'name' => Field::varchar('Name', 'frontend'),
                'theme_id' => Field::varchar('Theme ID', $frontend),
            ]),
            Field::array('adminhtml', [
                'name' => Field::varchar('Name', 'adminhtml'),
                'theme_id' => Field::varchar('Theme ID', $admin),
            ]),
        ];

        return ['themes' => Section::table('themes', 'Active Themes', $this->getDescription(), $themes)];
    }
}
