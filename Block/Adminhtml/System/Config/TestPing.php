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
 * Renders the "Send All Data Now" button. Save the config first, then it
 * sends every report slot immediately - so the dashboard has real data without
 * waiting for the cron ticks.
 */
class TestPing extends Field
{
    /**
     * Assigns the phtml template that renders the Test Ping button.
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        $this->setTemplate('StackNuts_StackGauge::system/config/test_ping.phtml');
        return $this;
    }

    /**
     * Strips the scope/website/default-value UI affordances before rendering.
     *
     * This is a button, not a real config value, so those don't apply.
     *
     * @param AbstractElement $element
     */
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

    /**
     * Passes the element's HTML id and the test-ping AJAX URL to the template.
     *
     * @param AbstractElement $element
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->addData([
            'html_id' => $element->getHtmlId(),
            'ajax_url' => $this->_urlBuilder->getUrl('stacknuts_stackgauge/system_config/testping'),
        ]);

        return $this->_toHtml();
    }
}
