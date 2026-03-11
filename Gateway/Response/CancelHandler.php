<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Response;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use PayU\Gateway\Gateway\SubjectReader;
use PayU\Gateway\Model\Payment\TransferObjectFactory;
use PayU\Gateway\Model\Payment\Operations\TransactionUpdateOperation;

/**
 * class CancelHandler
 * @package PayU\Gateway\Gateway\Response
 */
class CancelHandler implements HandlerInterface
{
    /**
     * @param SubjectReader $subjectReader
     * @param OrderFactory $orderFactory
     * @param TransferObjectFactory $transferFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param TransactionUpdateOperation $transactionUpdateOps
     */
    public function __construct(
        private readonly SubjectReader $subjectReader,
        private readonly OrderFactory $orderFactory,
        private readonly TransferObjectFactory $transferFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TransactionUpdateOperation $transactionUpdateOps
    ) {
    }

    /**
     * @param array $handlingSubject
     * @param array $response
     * @return void
     * @throws LocalizedException
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);
        /** @var Payment $orderPayment */
        $orderPayment = $paymentDO->getPayment();

        $message = 'Payment transaction amount of %1 was canceled by user on PayU.<br/>' . 'PayU reference "%2"<br/>';
        $transactionInfo = $this->subjectReader->readResponse($response);

        $payUReference = $transactionInfo->getPayUReference();
        $incrementId = $transactionInfo->getMerchantReference();

        if ($incrementId) {
            $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
            $payment = $order->getPayment();

            if (!$payment || $payment->getMethod() != $orderPayment->getMethod()) {
                throw new LocalizedException(
                    __("This payment didn't work out because we can't find this order.")
                );
            }

            if ((int)$order->getId() > 0) {
                $message = __(
                    $message,
                    $order->getBaseCurrency()->formatTxt($order->getBaseTotalDue()),
                    $payUReference
                );

                $payment->setAmountCanceled($payment->getBaseAmountOrdered());
                $payment->setBaseAmountCanceled($payment->getBaseAmountOrdered());
                $this->transactionUpdateOps->update(
                    $order,
                    $this->transferFactory->create(
                        [
                            'data' => ['txn' => json_decode($transactionInfo->toJson())]
                        ]
                    )
                );

                $order->addCommentToStatusHistory($message);
                $order->cancel();
                $this->orderRepository->save($order);
            }
        }
    }
}
