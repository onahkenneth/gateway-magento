<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\OrderFactory;
use PayU\Gateway\Model\Constants\TransactionState;
use PayU\Gateway\Model\Payment\Operations\CreateInvoiceOperation;
use PayU\Gateway\Model\Payment\Operations\TransactionUpdateOperation;
use PayU\Gateway\Model\Payment\Operations\ProcessFraudOperation;
use Psr\Log\LoggerInterface;

/**
 * class AbstractOperation
 * @package PayU\Gateway\Model\Payment
 */
abstract class AbstractOperation
{
    /**
     * @param Validator $validator
     * @param LoggerInterface $logger
     * @param OrderFactory $orderFactory
     * @param BuilderInterface $transactionBuilder
     * @param ProcessFraudOperation $fraudOperation
     * @param CreateInvoiceOperation $invoiceOperation
     * @param OrderRepositoryInterface $orderRepository
     * @param TransactionUpdateOperation $transactionOperation
     * @param OrderPaymentRepositoryInterface $paymentRepository
     */
    public function __construct(
        protected readonly Validator $validator,
        protected readonly LoggerInterface $logger,
        protected readonly OrderFactory $orderFactory,
        protected readonly BuilderInterface $transactionBuilder,
        protected readonly ProcessFraudOperation $fraudOperation,
        protected readonly CreateInvoiceOperation $invoiceOperation,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly TransactionUpdateOperation $transactionOperation,
        protected readonly OrderPaymentRepositoryInterface $paymentRepository,

    ) {
    }

    /**
     * @param OrderInterface $order
     * @param DataObject $transactionInfo
     * @param OrderPaymentInterface $payment
     * @return void
     */
    protected function addStatusCommentOnUpdate(
        OrderInterface $order,
        DataObject $transactionInfo,
        OrderPaymentInterface $payment
    ): void {
        $transactionId = $transactionInfo->getTranxId();

        if ($payment->getIsTransactionApproved()) {
            $message = __(
                'Transaction %1 has been approved.  <br /> Amount %2 <br />Transaction type: %3  <br /> Transaction status: "%4"',
                $transactionId,
                $order->getBaseCurrency()->formatTxt($payment->getAmountOrdered()),
                $transactionInfo->getTransactionType(),
                $transactionInfo->getTransactionState()
            );
            $order->addCommentToStatusHistory($message);
        } elseif ($payment->getIsTransactionPending()) {
            $message = __(
                'Transaction %1 is pending payment.  <br /> Amount %2 <br />Transaction type: %3  <br /> Transaction status: "%4"',
                $transactionId,
                $order->getBaseCurrency()->formatTxt($payment->getAmountOrdered()),
                $transactionInfo->getTransactionType(),
                $transactionInfo->getTransactionState()
            );
            $order->addCommentToStatusHistory($message);
        } elseif ($payment->getIsTransactionDenied()) {
            $message = __(
                'Transaction %1 has been voided/declined.  <br /> Amount %2 <br />Transaction type: %3  <br /> Transaction status: "%4".',
                $transactionId,
                $order->getBaseCurrency()->formatTxt($payment->getAmountOrdered()),
                $transactionInfo->getTransactionType(),
                $transactionInfo->getTransactionState()
            );
            $order->addCommentToStatusHistory($message);
        }

        $this->orderRepository->save($order);
    }

    /**
     * Fill payment with credit card data from response from PayU.
     *
     * @param OrderInterface $order
     * @param DataObject $transactionInfo
     * @return void
     */
    protected function updatePayment(OrderInterface $order, DataObject $transactionInfo): void
    {
        $payment = $order->getPayment();
        $payment->setLastTransId($transactionInfo->getTranxId())
            ->setTransactionId($transactionInfo->getTranxId())
            ->setParentTransactionId(null)
            ->setIsTransactionClosed(true)
            ->setAdditionalInformation(
                [Order\Payment\Transaction::RAW_DETAILS => $transactionInfo->getPaymentData()]
            )
            ->setTransactionAdditionalInfo(TransactionState::REAL_TRANSACTION_ID_KEY->value, $transactionInfo->getTranxId());

        if ($transactionInfo->hasCreditCard()) {
            $cardData = $transactionInfo->getCardData();
            $payment->setCcTransId($cardData['txnId'])
                ->setCcOwner($cardData['owner'])
                ->setCcType($cardData['type'])
                ->setCcExpYear($cardData['expiryYear'])
                ->setCcNumberEnc($payment->encrypt($cardData['cardNumber']));
        }

        if ($transactionInfo->getTransactionState() == TransactionState::AWAITING_PAYMENT->value) {
            $payment->setIsTransactionPending(true);
        }

        if ($transactionInfo->isFraudDetected()) {
            $payment->setIsFraudDetected(true);
        }

        $this->paymentRepository->save($payment);
    }
}
