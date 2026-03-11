<?php
/**
 * Copyright © 2026 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Observer;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use PayU\Gateway\Model\Trait\GetTransactionTrait;

class PaymentRefundObserver implements ObserverInterface
{
    use GetTransactionTrait;

    /**
     * @param FilterBuilder $filterBuilder
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param TransactionRepositoryInterface $transactionRepository
     */
    public function __construct(
        protected readonly FilterBuilder $filterBuilder,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly TransactionRepositoryInterface $transactionRepository
    ) {}

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $payment = $event->getDataByKey('payment');
        $order = $payment->getOrder();
        $method = $payment->getMethodInstance();

        if (!$method || str_contains($method->getCode(), 'payu_gateway') === false) {
            return;
        }

        $objects = $order->getRelatedObjects();

        foreach ($objects as $object) {
            if ($object instanceof Transaction) {
                $parentTransaction = $this->getTransaction($payment->getParentTransactionId());

                if ($parentTransaction &&
                    $object->getParentId() === null &&
                    $parentTransaction->getData('txn_id') !== $object->getData('txn_id')
                ) {
                    $object->setParentId($parentTransaction->getId());
                }
            }
        }
    }
}
