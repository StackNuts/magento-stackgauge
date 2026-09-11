<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Admin repeater field for the log files LogReporter shows a recent-lines tail for (see
 * Model\Config::getMonitoredLogFiles()). Two columns: a friendly display name and the
 * actual filename in var/log.
 */
class LogFiles extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('name', ['label' => __('Name')]);
        $this->addColumn('file', ['label' => __('File (in var/log)')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Log File');
    }
}
