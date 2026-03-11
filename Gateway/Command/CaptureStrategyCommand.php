<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Command;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Helper\ContextHelper;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use PayU\Gateway\Gateway\SubjectReader;
use PayU\Gateway\Model\Adapter\PayUAdapterFactory;

/**
 * class CaptureStrategyCommand
 * @package PayU\Gateway\Gateway\Command
 */
class CaptureStrategyCommand implements CommandInterface
{
    private const AUTHORIZE = 'authorize';
    private const CAPTURE = 'settlement';
    private const FETCH_TXN_INFO = 'fetch_transaction_information';

    /**
     * Constructor
     *
     * @param CommandPoolInterface $commandPool
     * @param TransactionRepositoryInterface $transactionRepository
     * @param FilterBuilder $filterBuilder
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SubjectReader $subjectReader
     * @param PayUAdapterFactory $payuAdapterFactory
     */
    public function __construct(
        private readonly CommandPoolInterface $commandPool,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly FilterBuilder $filterBuilder,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SubjectReader $subjectReader,
        private readonly PayUAdapterFactory $payuAdapterFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(array $commandSubject)
    {
        $paymentDO = $this->subjectReader->readPayment($commandSubject);

        $command = $this->getCommand($paymentDO);
        $commandObj = $this->commandPool->get($command);
        $commandObj->execute($commandSubject);
    }

    /**
     * Get execution command name.
     *
     * @param PaymentDataObjectInterface $paymentDO
     * @return string
     * @throws LocalizedException
     */
    private function getCommand(PaymentDataObjectInterface $paymentDO): string
    {
        $payment = $paymentDO->getPayment();
        ContextHelper::assertOrderPayment($payment);

        // Allow invoice for order transactions to be capture online
        if ($this->isExistsOrderTransaction($payment)) {
            return self::FETCH_TXN_INFO;
        }

        // if auth transaction does not exist then execute authorize&capture command
        $existsCapture = $this->isExistsCaptureTransaction($payment);
        $authorizeTxn = $payment->getAuthorizationTransaction();

        if (!$authorizeTxn && !$existsCapture) {
            return self::AUTHORIZE;
        }

        // do capture for authorization transaction
        if (
            !$existsCapture &&
            $authorizeTxn &&
            !$this->isExpiredAuthorization($payment, $paymentDO->getOrder())
        ) {
            return self::CAPTURE;
        }

        return self::FETCH_TXN_INFO;
    }

    /**
     * Checks if authorization transaction has not expired yet.
     *
     * @param OrderPaymentInterface $payment
     * @param OrderAdapterInterface $orderAdapter
     * @return bool
     * @throws LocalizedException
     */
    private function isExpiredAuthorization(OrderPaymentInterface $payment, OrderAdapterInterface $orderAdapter): bool
    {
        $adapter = $this->payuAdapterFactory->create((int)$orderAdapter->getStoreId());
        $transaction = $adapter->search($payment->getLastTransId());

        return $transaction->getTransactionState() === \PayUSdk\Api\Data\TransactionInterface::STATE_EXPIRED;
    }

    /**
     * Check if capture transaction already exists
     *
     * @param OrderPaymentInterface $payment
     * @return bool
     */
    private function isExistsCaptureTransaction(OrderPaymentInterface $payment): bool
    {
        $this->searchCriteriaBuilder->addFilters(
            [
                $this->filterBuilder
                    ->setField('payment_id')
                    ->setValue($payment->getId())
                    ->create(),
            ]
        );

        $this->searchCriteriaBuilder->addFilters(
            [
                $this->filterBuilder
                    ->setField('txn_type')
                    ->setValue(TransactionInterface::TYPE_CAPTURE)
                    ->create(),
            ]
        );

        $searchCriteria = $this->searchCriteriaBuilder->create();

        $count = $this->transactionRepository->getList($searchCriteria)->getTotalCount();

        return (bool) $count;
    }

    /**
     * Check if capture transaction already exists
     *
     * @param OrderPaymentInterface $payment
     * @return bool
     */
    private function isExistsOrderTransaction(OrderPaymentInterface $payment): bool
    {
        $this->searchCriteriaBuilder->addFilters(
            [
                $this->filterBuilder
                    ->setField('payment_id')
                    ->setValue($payment->getId())
                    ->create(),
            ]
        );

        $this->searchCriteriaBuilder->addFilters(
            [
                $this->filterBuilder
                    ->setField('txn_type')
                    ->setValue(TransactionInterface::TYPE_ORDER)
                    ->create(),
            ]
        );

        $searchCriteria = $this->searchCriteriaBuilder->create();

        $count = $this->transactionRepository->getList($searchCriteria)->getTotalCount();

        return (bool) $count;
    }
}
