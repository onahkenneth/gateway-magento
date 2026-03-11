<?php
/**
 * Copyright © 2024 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use PayU\Gateway\Model\Trait\GetPayUReferenceTrait;

class Index extends Action
{
    use GetPayUReferenceTrait;

    /**
     * @var string?
     */
    protected $code = null;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Registry?
     */
    protected $coreRegistry = null;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        StoreManagerInterface $storeManager,
        Registry $registry,
        ScopeConfigInterface $scopeConfig,
        OrderRepositoryInterface $orderRepository,
    ) {
        parent::__construct($context);

        $this->encryptor = $encryptor;
        $this->storeManager = $storeManager;
        $this->coreRegistry = $registry;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $orderId = $this->getRequest()->getPostValue('order_id');
        $order = $this->orderRepository->get($orderId);

        if ((int)$order->getEntityId() === 0 || $order->getEntityId() !== $orderId) {
            $resultJson->setData([
                'message' => 'Order not found',
                'success' => false
            ]);

            return $resultJson;
        }

        $payment = $order->getPayment();

        $code = $payment->getData('method');

        if (!str_contains($code, 'payu_gateway')) {
            $resultJson->setData([
                'message' => 'Payment method not a PayU Gateway method',
                'success' => false
            ]);

            return $resultJson;
        }

        $methodInstance = $payment->getMethodInstance();
        $payUReference = $this->getPayUOrderReference($payment);
        $methodInstance->fetchTransactionInfo($payment, $payUReference);

        $transactionAdditionalInfo = $payment->getTransactionAdditionalInfo();
        $transactionInfo = $transactionAdditionalInfo['transactionInfo'] ?? null;

        if (!$transactionInfo) {
            $resultJson->setData([
                'message' => 'No transaction data',
                'success' => false
            ]);

            return $resultJson;
        }

        $resultJson->setData(['message' => 'Good', 'success' => true, 'data' => $transactionInfo->toArray()['txn']]);

        return $resultJson;
    }
}
