<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Response\Adminhtml;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use PayU\Gateway\Gateway\SubjectReader;

/**
 * class RefundHandler
 * @package PayU\Gateway\Gateway\Response
 */
class RefundHandler implements HandlerInterface
{
    /**
     * @param SubjectReader $subjectReader
     */
    public function __construct(private readonly SubjectReader $subjectReader)
    {}

    /**
     * @inheritDoc
     */
    public function handle(array $handlingSubject, array $response) {
        $responseDO = $this->subjectReader->readResponse($response);

        if (!$responseDO) {
            return;
        }

        $paymentDO = $this->subjectReader->readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();


        $message = 'The refund amount of %1 %2 on the payment gateway. <br />PayU Reference: %3';
        $message = __(
            $message,
            $payment->formatPrice($payment->getCreditmemo()->getBaseGrandTotal()),
            $responseDO->getSuccessful() ? 'was succesfully credited' : 'is pending approval',
            $responseDO->getPayUReference()
        );
        $order->addStatusHistoryComment($message);
        $payment->setTransactionAdditionalInfo(Transaction::RAW_DETAILS, $responseDO->toArray());
    }
}
