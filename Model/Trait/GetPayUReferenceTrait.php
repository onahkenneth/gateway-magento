<?php
/**
 * Copyright © 2026 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Trait;

use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

trait GetPayUReferenceTrait
{
    /**
     * Get PayU order transaction reference
     *
     * @param InfoInterface|OrderPaymentInterface $payment
     * @return ?string
     */
    protected function getPayUOrderReference(InfoInterface|OrderPaymentInterface $payment): ?string
    {
        return $payment->getAdditionalInformation('payuReference') ??
            $payment->getLastTransId();
    }

    /**
     * Get PayU refund transaction reference
     *
     * @param InfoInterface|OrderPaymentInterface $payment
     * @return string
     */
    protected function getPayURefundReference(InfoInterface|OrderPaymentInterface $payment): string
    {
        $txnId = $payment->getCreditmemo()->getInvoice()->getTransactionId() ??
            $payment->getLastTransId();

        if (stripos($txnId, '-') !== false) {
            $parts = explode('-', $txnId);
            $txnId = $parts[0];
        }

        return $txnId;
    }
}
