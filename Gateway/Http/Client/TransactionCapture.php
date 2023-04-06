<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Http\Client;

/**
 * class TransactionCapture
 * @package PayU\Gateway\Gateway\Http\Client
 */
class TransactionCapture extends AbstractTransaction
{
    /**
     * @param array $data
     * @return mixed
     */
    protected function process(array $data): mixed
    {
        $storeId = (int)$data['store_id'] ?? null;
        // not sending store id
        unset($data['store_id']);

        return $this->adapterFactory->create($storeId)->capture($data);
    }
}
