<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter\Concern;

use StackNuts\StackGauge\Api\DeclaresSectionInterface;

/**
 * Default DeclaresSectionInterface::getSection() for reporters in the Commerce domain
 * category.
 */
trait CommerceSectionTrait
{
    public function getSection(): string
    {
        return DeclaresSectionInterface::SECTION_COMMERCE;
    }
}
