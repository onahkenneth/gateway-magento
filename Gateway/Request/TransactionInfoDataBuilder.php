<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;
use PayU\Gateway\Gateway\SubjectReader;

/**
 * class TransactionInfoDataBuilder
 * @package PayU\Gateway\Gateway\Request
 */
class TransactionInfoDataBuilder implements BuilderInterface
{
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
        $orderPayment = $paymentDO->getPayment();

        return [
            self::PAYU_REFERENCE => $orderPayment->getAdditionalInformation('payUReference')
        ];
    }
}
