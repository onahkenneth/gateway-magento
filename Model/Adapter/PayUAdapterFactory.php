<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Adapter;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PayU\Gateway\Gateway\Config\Config;

/**
 * class PayUAdapterFactory
 * @package PayU\Gateway\Model\Adapter
 */
class PayUAdapterFactory
{
    /**
     * @var string
     */
    private readonly string $class;

    /**
     * @param Config $config
     * @param FrontendInterface $cache
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        protected Config $config,
        protected FrontendInterface $cache,
        protected ObjectManagerInterface $objectManager
    ) {
        $this->class = PayUAdapter::class;
    }

    /**
     * Creates instance of Braintree Adapter.
     *
     * @param int|null $storeId if null is provided as an argument, then current scope will be resolved
     * by \Magento\Framework\App\Config\ScopeCodeResolver (useful for most cases) but for adminhtml area the store
     * should be provided as the argument for correct config settings loading.
     * @param string|null $methodCode
     * @return PayUAdapter
     */
    public function create(?int $storeId = null, ?string $methodCode = ''): PayUAdapter
    {
        if ($methodCode) {
            $this->config->setMethodCode($methodCode);
        }

        return $this->objectManager->create(
            $this->class,
            [
                'safeKey' => $this->config->getSafeKey($storeId),
                'username' => $this->config->getApiUsername($storeId),
                'password' => $this->config->getApiPassword($storeId),
                'environment' => $this->config->getEnvironment($storeId),
                'enterprise' => $this->config->isEnterprise($storeId),
                'paymentMethods' => $this->config->getSupportedPaymentMethods($storeId),
                'cache' => $this->cache,
                'directoryList' => $this->objectManager->get(DirectoryList::class),
                'serializer' => $this->objectManager->get(SerializerInterface::class),
            ]
        );
    }
}
