<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Request;

use InvalidArgumentException;
use Magento\Payment\Gateway\Request\BuilderInterface;
use PayU\Gateway\Gateway\SubjectReader;
use PayU\Gateway\Model\Trait\GetPayUReferenceTrait;

/**
 * class TransactionInfoDataBuilder
 * @package PayU\Gateway\Gateway\Request
 */
class TransactionInfoDataBuilder implements BuilderInterface
{
    use GetPayUReferenceTrait;

    public const ORDER_ID = 'order_id';

    public const PAYU_REFERENCE = 'payUReference';

    /**
     * Constructor
     * @param SubjectReader $subjectReader
     */
    public function __construct(private readonly SubjectReader $subjectReader)
    {
    }

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);

        try {
            $txnId = $this->subjectReader->readTransactionId($buildSubject);
        } catch(InvalidArgumentException $ex) {
            $txnId = null;
        }

        $order = $paymentDO->getOrder();
        $orderPayment = $paymentDO->getPayment();
        $reference = $this->getPayUOrderReference($orderPayment);

        return [
            self::PAYU_REFERENCE => $txnId ?? $reference,
            self::ORDER_ID => $order->getOrderIncrementId(),
        ];
    }
}
