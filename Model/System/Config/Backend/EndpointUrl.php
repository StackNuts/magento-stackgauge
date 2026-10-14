<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\System\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Refuses to save a dashboard endpoint that isn't https:// - every report carries the API key
 * and signed store data, so a plain-http endpoint would send both in the clear. Transport
 * enforces the same rule at send time for a value set some other way (config:set, env.php).
 */
class EndpointUrl extends Value
{
    /**
     * Validates the endpoint before it's saved.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        $this->setValue($value);

        if ($value !== '' && strtolower((string)parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            throw new LocalizedException(__('The StackGauge dashboard endpoint URL must start with https://.'));
        }

        return parent::beforeSave();
    }
}
