<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Request\BuilderInterface;
use PayU\Gateway\Gateway\SubjectReader;
use PayU\Gateway\Model\Trait\GetPayUReferenceTrait;
use PayUSdk\Model\Currency;
use PayUSdk\Model\Total;
use PayUSdk\Model\Transaction;

/**
 * class PayUAdapterFactory
 * @package PayU\Gateway\Model\Adapter
 */
class RefundDataBuilder implements BuilderInterface
{
    use GetPayUReferenceTrait;

    public const TRANSACTION = 'transaction';
    public const PAYU_REFERENCE = 'payUReference';
    public const MERCHANT_REFERENCE = 'merchantReference';

    /**
     * Constructor
     *
     * @param SubjectReader $subjectReader
     */
    public function __construct(private readonly SubjectReader $subjectReader)
    {
    }

    /**
     * @param array $buildSubject
     * @return array
     * @throws LocalizedException
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $payment = $paymentDO->getPayment();

        $transactionId = $this->getPayURefundReference($payment);

        if (!$transactionId) {
            throw new LocalizedException(__('No capture transaction ID to proceed with refund.'));
        }

        $currency = new Currency(['code' => $order->getCurrencyCode()]);
        $total = new Total();
        $total->setCurrency($currency)
            ->setAmount(floatval($this->subjectReader->readAmount($buildSubject)));

        $transaction = new Transaction();
        $transaction->setTotal($total);

        return [
            self::TRANSACTION => $transaction,
            self::PAYU_REFERENCE => $transactionId,
            self::MERCHANT_REFERENCE => $order->getOrderIncrementId(),
        ];
    }
}
