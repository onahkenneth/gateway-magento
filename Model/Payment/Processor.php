<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order\Payment;
use PayU\Gateway\Model\Constants\RedirectPage;
use PayU\Gateway\Model\Constants\ResultCode;
use PayU\Gateway\Model\Lock\TransactionFactory as TransactionLockFactory;
use PayU\Gateway\Model\Payment\Operations\PaymentNotificationOperation;
use PayU\Gateway\Model\ResourceModel\TransactionFactory as TransactionLockResourceFactory;
use stdClass;

/**
 * class Processor
 * @package PayU\Gateway\Model\Payment
 */
class Processor
{
    /**
     * @param Logger $logger
     * @param OrderFactory $orderFactory
     * @param OrderRepository $orderRepository
     * @param PaymentNotificationOperation $ipnOperation
     * @param TransactionLockFactory $transactionLockFactory
     * @param TransactionLockResourceFactory $transactionLockResourceFactory
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly OrderFactory $orderFactory,
        private readonly OrderRepository $orderRepository,
        private readonly PaymentNotificationOperation $ipnOperation,
        private readonly TransactionLockFactory $transactionLockFactory,
        private readonly TransactionLockResourceFactory $transactionLockResourceFactory,
        private ?DataObject $transactionInfo = null
    ) {
    }

    /**
     * Process return from PayU after payment
     *
     * @param OrderInterface $order
     * @param string $payUReference
     * @param string $processId
     * @param string $name
     * @return array
     * @throws LocalizedException
     */
    public function return(
        OrderInterface $order,
        string $payUReference,
        string $processId,
        string $processClass
    ): array {
        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();
        $method->fetchTransactionInfo($payment, $payUReference);

        $transactionAdditionalInfo = $payment->getTransactionAdditionalInfo();
        $transactionInfo = $transactionAdditionalInfo['transactionInfo'] ?? null;

        if (!$transactionInfo) {
            return [false, 'Payment transaction not found.'];
        }

        $transactionInfo->setProcessId($processId);
        $transactionInfo->setProcessClass($processClass);
        $payment->setTransactionAdditionalInfo('transactionInfo', $transactionInfo);

        $this->transactionInfo = $transactionInfo;

        if ($payment->getIsTransactionApproved() ||
            $payment->getIsTransactionProcessing() ||
            $payment->getIsTransactionPending() // EFT awaiting payment
        ) {
            $method->acceptPayment($payment);

            return [true, $transactionInfo->getDisplayMessage() ?? 'Payment successful.'];
        }

        $method->denyPayment($payment);

        return [false, $transactionInfo->getDisplayMessage() ?? 'Payment failed.'];
    }

    /**
     * @param OrderInterface $order
     * @param stdClass $ipnData
     * @param string $processId
     * @param string $processClass
     * @return void
     * @throws LocalizedException
     */
    public function notify(OrderInterface $order, stdClass $ipnData, string $processId, string $processClass): void
    {
        if ($order->hasInvoices()) {
            $this->logger->debug(['info' => "IPN => ($processId) ($processClass) : order already processed.", 'response' => $ipnData]);

            return;
        }

        $payUReference = $ipnData->PayUReference;
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();
        $method->fetchTransactionInfo($payment, $payUReference);

        $transactionAdditionalInfo = $payment->getTransactionAdditionalInfo();
        $transactionInfo = $transactionAdditionalInfo['transactionInfo'] ?? null;

        if (!$transactionInfo) {
            return;
        }

        $transactionInfo->setProcessId($processId);
        $transactionInfo->setProcessClass($processClass);
        $payment->setTransactionAdditionalInfo('transactionInfo', $transactionInfo);

        $this->transactionInfo = $transactionInfo;

        $resultCode = $transactionInfo->getResultCode() ?? 'N/A';

        if (in_array($resultCode, array_column(ResultCode::cases(), 'value'))) {
            $comment = "<strong>-----PAYU NOTIFICATION RECEIVED---</strong><br />";
            $comment .= '<strong>Payment unsuccessful: </strong><br />';
            $comment .= "Transaction Type: " . $transactionInfo->getTransactionType() . "<br />";
            $comment .= "PayU Reference: " . $transactionInfo->getTranxId() . "<br />";
            $comment .= "Point Of Failure: " . $transactionInfo->getPointOfFailure() . "<br />";
            $comment .= "Result Code: " . $transactionInfo->getResultCode() . "<br />";
            $comment .= "Result Message: " . $transactionInfo->getResultMessage();
            $order->addCommentToStatusHistory($comment, true);
            $order->cancel();
            $this->orderRepository->save($order);

            return;
        }

        $this->ipnOperation->notify($payment, $ipnData);
    }

    /**
     * @param OrderInterface $order
     * @param string $payUReference
     * @return void
     * @throws LocalizedException
     */
    public function cancel(OrderInterface $order, string $payUReference): void
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation('payUReference', $payUReference);
        $payment->getMethodInstance()->cancel($payment);
    }

    /**
     * @param string $incrementId
     * @param string $processId
     * @param string $processClass
     * @return bool
     * @throws \Exception
     */
    public function canProceed(string $incrementId, string $processId, string $processClass): bool
    {
        $transaction = $this->transactionLockFactory->create();
        $resourceModel = $this->transactionLockResourceFactory->create();
        $resourceModel->load($transaction, $incrementId, 'increment_id');

        if ($transaction->getId() > 0) {
            return false;
        }

        $transaction->setIncrementId($incrementId)
            ->setLock(true)
            ->setStatus('processing')
            ->setProcessId($processId)
            ->setProcessClass($processClass);

        try {
            $resourceModel->save($transaction);
        } catch (AlreadyExistsException $exception) {
            $this->logger->debug([
                'error' => "($incrementId) ($processId) $processClass: failed to obtain lock. Already locked by another process"
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param string $incrementId
     * @param string $processId
     * @param string $status
     * @return void
     * @throws AlreadyExistsException
     */
    public function updateTransactionLog(string $incrementId, string $processId, string $status = 'complete')
    {
        $transaction = $this->transactionLockFactory->create();
        $resourceModel = $this->transactionLockResourceFactory->create();
        $resourceModel->load($transaction, $incrementId, 'increment_id');

        if ($transaction->getId() === 0) {
            return;
        }

        if ($transaction->getProcessId() !== $processId) {
            return;
        }

        try {
            $transaction->setStatus($status);
            $transaction->setLock(false);
            $resourceModel->save($transaction);
        } catch (AlreadyExistsException $exception) {
            // It's fine we are just updating
        }
    }

    /**
     * @param string $incrementId
     * @param string $payUReference
     * @return int
     */
    public function redirectTo(string $incrementId, string $payUReference): int
    {
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

        if ((int)$order->getId() === 0) {
            throw new LocalizedException(__("Order not found"));
        }

        $payment = $order->getPayment();
        $payment->setCaptureOperationCalled(false);
        $method = $payment->getMethodInstance();
        $method->fetchTransactionInfo($payment, $payUReference);

        $transactionAdditionalInfo = $payment->getTransactionAdditionalInfo();
        /** @var DataObject $transactionInfo */
        $transactionInfo = $transactionAdditionalInfo['transactionInfo'] ?? null;
        $this->transactionInfo = $transactionInfo;

        switch ($transactionInfo->getTransactionState()) {
            case 'FAILED':
            case 'TIMEOUT':
            case 'EXPIRED':
                $page = RedirectPage::FAILED_PAGE->value;
                break;
            case 'NEW':
            case 'PROCESSING':
                $page = RedirectPage::PENDING_PAGE->value;
                break;
            default:
                $page = RedirectPage::SUCCESS_PAGE->value;
        }

        if ($transactionInfo->isCancelPayflex($order) || $transactionInfo->isMasterpassTimeout($order)) {
            $page = RedirectPage::RETURN_CART->value;
        }

        return $page;
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    public function isCancelPayflex(OrderInterface $order): bool
    {
        return $this->transactionInfo->isCancelPayflex($order);
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    public function isMasterpassTimeout(OrderInterface $order): bool
    {
        return $this->transactionInfo->isMasterpassTimeout($order);
    }

    /**
     * @return bool
     */
    public function isPaymentPending(): bool
    {
        return $this->transactionInfo->isAwaitingPayment();
    }

    /**
     * @return bool
     */
    public function isPaymentProcessing(): bool
    {
        return $this->transactionInfo->isPaymentProcessing();
    }

    /**
     * @return bool
     */
    public function isPaymentFailed(): bool
    {
        return $this->transactionInfo->isPaymentFailed();
    }
}
