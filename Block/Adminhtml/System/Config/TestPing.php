<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Test Ping" button. Save the config first, then this checks connectivity and,
 * on success, sends one full report immediately - so the dashboard has real data without
 * waiting for the next hourly cron tick.
 */
class TestPing extends Field
{
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        $this->setTemplate('StackNuts_StackGauge::system/config/test_ping.phtml');
        return $this;
    }

    public function render(AbstractElement $element)
    {
        $element = clone $element;
        if (method_exists($element, 'unsScope')) {
            $element->unsScope();
        }
        if (method_exists($element, 'unsCanUseWebsiteValue')) {
            $element->unsCanUseWebsiteValue();
        }
        if (method_exists($element, 'unsCanUseDefaultValue')) {
            $element->unsCanUseDefaultValue();
        }
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $this->addData([
            'html_id' => $element->getHtmlId(),
            'ajax_url' => $this->_urlBuilder->getUrl('stacknuts_stackgauge/system_config/testping'),
        ]);

        return $this->_toHtml();
    }
}
