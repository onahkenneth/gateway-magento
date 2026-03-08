<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Payment\Operations;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use PayU\Gateway\Gateway\Config\Config;

/**
 * class TransactionUpdateOperation
 * @package PayU\Gateway\Model\Payment\Operations
 */
class TransactionUpdateOperation
{
    /**
     * @param Config $config
     * @param FilterBuilder $filterBuilder
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param BuilderInterface $transactionBuilder
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param TransactionRepositoryInterface $transactionRepository
     */
    public function __construct(
        protected readonly Config $config,
        protected readonly FilterBuilder $filterBuilder,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly BuilderInterface $transactionBuilder,
        protected readonly OrderPaymentRepositoryInterface $paymentRepository,
        protected readonly TransactionRepositoryInterface $transactionRepository
    ) {
    }

    /**
     * @param OrderInterface $order
     * @param DataObject $transactionInfo
     * @return void
     * @throws LocalizedException
     */
    public function update(OrderInterface $order, DataObject $transactionInfo): void {
        $payment = $order->getPayment();
        $parentTransaction = $this->getTransaction($payment->getParentTransactionId());
        $currentTransaction = $this->getTransaction($payment->getTransactionId());

        if ($currentTransaction && $parentTransaction) {
            if ((int) $currentTransaction->getParentId() !== (int) $parentTransaction->getTransactionId()) {
                $currentTransaction->setParentId($parentTransaction->getTransactionId());
            }

            $formattedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );
            $message = __('The order transaction amount is %1.', $formattedPrice);

            if ($payment->getBaseAmountCanceled()) {
                $parentTransaction->setIsClosed(true);
            }

            $payment->addTransactionCommentsToOrder(
                $parentTransaction,
                $message
            );
            $this->transactionRepository->save($parentTransaction);
            $this->transactionRepository->save($currentTransaction);
        } else {
            $payment->setIsTransactionClosed(true);

            $transactionBuilder = $this->transactionBuilder->setPayment($payment);
            $transactionBuilder->setOrder($order);
            $transactionBuilder->setFailSafe(true);
            $transactionBuilder->setTransactionId($transactionInfo->getPayUReference());
            $transaction = $transactionBuilder->build($this->getPaymentAction((int)$order->getStoreId()));
            $data = $transaction->getAdditionalInformation();
            $transaction->setAdditionalInformation(
                Order\Payment\Transaction::RAW_DETAILS,
                 ($data[Order\Payment\Transaction::RAW_DETAILS] ?? [])+ $transactionInfo->getPaymentData()
            );
            $this->transactionRepository->save($transaction);
        }
    }

    /**
     * @param int $storeId
     * @return string
     */
    private function getPaymentAction(int $storeId): string
    {
        $transactionType = $this->config->getTransactionType($storeId);

        return match ($transactionType) {
            'order', => TransactionInterface::TYPE_ORDER,
            'authorize' => TransactionInterface::TYPE_AUTH,
            'authorize_capture' => TransactionInterface::TYPE_CAPTURE
        };
    }

    /**
     * Get payment transaction
     *
     * @param ?string $txnId
     * @return TransactionInterface|null
     */
    private function getTransaction(?string $txnId): ?TransactionInterface
    {
        $this->searchCriteriaBuilder->addFilters(
            [
                $this->filterBuilder
                    ->setField('txn_id')
                    ->setValue($txnId)
                    ->create(),
            ]
        );
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $result = $this->transactionRepository->getList($searchCriteria);

        return $result->getTotalCount() > 0 ? current($result->getItems()) : null;
    }
}
