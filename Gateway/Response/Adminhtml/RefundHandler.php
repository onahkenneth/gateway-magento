<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Response\Adminhtml;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Model\InfoInterface;
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
    public function __construct(
        private readonly SubjectReader $subjectReader
    ) {}

    /**
     * @inheritDoc
     */
    public function handle(array $handlingSubject, array $response) {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);
        $orderPayment = $paymentDO->getPayment();

        $this->shouldCloseParentTransaction($orderPayment);
    }

    /**
     * Whether parent transaction should be closed
     *
     * @param InfoInterface $orderPayment
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function shouldCloseParentTransaction(InfoInterface $orderPayment): bool
    {
        return !$orderPayment->getCreditmemo()->getInvoice()->canRefund();
    }
}
