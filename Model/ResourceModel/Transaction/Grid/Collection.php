<?php
/**
 * Copyright © 2024 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

 declare(strict_types=1);

namespace PayU\Gateway\Model\ResourceModel\Transaction\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * class Collection
 * @package PayU\Gateway\Model\ResourceModel\Transaction\Grid
 */
class Collection extends SearchResult
{
    protected function _initSelect(): Collection
    {
        parent::_initSelect();
        $this->setOrder('entity_id', 'DESC');
        return $this;
    }
}
