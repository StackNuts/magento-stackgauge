<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;
use Magento\Store\Model\ScopeInterface as StoreScopeInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

class ThemeReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param ThemeProviderInterface $themeProvider
     * @param DesignInterface $design
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ThemeProviderInterface $themeProvider,
        private readonly DesignInterface $design,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the themes reporter.
     */
    public function getName(): string
    {
        return 'themes';
    }

    /**
     * Human-readable label for the themes reporter block.
     */
    public function getLabel(): string
    {
        return 'Themes';
    }

    /**
     * One-line summary of what the themes reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Active frontend and admin themes.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the active frontend theme of every store view (a theme_id of its own plus the theme
     * path it resolves to, e.g. Magento/luma), and the configured admin theme. The admin theme is
     * not stored in design/theme/theme_id - it comes from the area's DI configuration.
     */
    public function getStatus(): array
    {
        $themes = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $themeId = (string)$this->scopeConfig->getValue(
                'design/theme/theme_id',
                StoreScopeInterface::SCOPE_STORES,
                $store->getId()
            );
            $name = 'frontend:' . $store->getCode();
            $themes[] = $this->field->array($name, [
                'name' => $this->field->varchar('Name', $name),
                'theme_id' => $this->field->varchar('Theme ID', $themeId),
                'theme_path' => $this->field->varchar('Theme', $this->themePath($themeId)),
            ]);
        }

        $themes[] = $this->field->array('adminhtml', [
            'name' => $this->field->varchar('Name', 'adminhtml'),
            'theme_id' => $this->field->varchar('Theme ID', ''),
            'theme_path' => $this->field->varchar('Theme', $this->adminThemePath()),
        ]);

        return ['themes' => $this->section->table('themes', 'Active Themes', $this->getDescription(), $themes)];
    }

    /**
     * The theme path for a numeric theme id, or '' when it is unset or no longer exists.
     *
     * @param string $themeId
     */
    private function themePath(string $themeId): string
    {
        if ($themeId === '') {
            return '';
        }

        try {
            return (string)$this->themeProvider->getThemeById((int)$themeId)->getThemePath();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The admin area's configured theme path, or '' if it can't be resolved.
     */
    private function adminThemePath(): string
    {
        try {
            return (string)$this->design->getConfigurationDesignTheme('adminhtml');
        } catch (Throwable) {
            return '';
        }
    }
}
