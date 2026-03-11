<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Adapter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use PayU\Gateway\Gateway\Request\AdditionalInfoDataBuilder;
use PayU\Gateway\Gateway\Request\AddressDataBuilder;
use PayU\Gateway\Gateway\Request\BasketDataBuilder;
use PayU\Gateway\Gateway\Request\CaptureDataBuilder;
use PayU\Gateway\Gateway\Request\CustomerDataBuilder;
use PayU\Gateway\Gateway\Request\FraudDataBuilder;
use PayU\Gateway\Gateway\Request\PaymentCardDetailsDataBuilder;
use PayU\Gateway\Gateway\Request\PaymentUrlDataBuilder;
use PayU\Gateway\Gateway\Request\RefundDataBuilder;
use PayU\Gateway\Gateway\Request\TransactionInfoDataBuilder;
use PayU\Gateway\Gateway\Request\TransactionTypeBuilder;
use PayU\Gateway\Gateway\Request\VoidDataBuilder;
use PayUSdk\Api\Data\TransactionInterface;
use PayUSdk\Api\ResponseInterface;
use PayUSdk\Framework\Action\Capture;
use PayUSdk\Framework\Action\Redirect;
use PayUSdk\Framework\Action\Refund;
use PayUSdk\Framework\Action\Sale;
use PayUSdk\Framework\Action\Search;
use PayUSdk\Framework\Action\VoidAuthorize;
use PayUSdk\Framework\Authentication;
use PayUSdk\Framework\Processor;
use PayUSdk\Framework\Response;
use PayUSdk\Framework\Soap\Context;
use PayUSdk\Model\Cart;
use PayUSdk\Model\Transaction;

/**
 * class PayUAdapter
 * @package PayU\Gateway\Model\Adapter
 */
class PayUAdapter
{
    /**
     * @var ?Context
     */
    protected ?Context $apiContext = null;

    /**
     * @param string $safeKey
     * @param string $username
     * @param string $password
     * @param string $environment
     * @param bool $enterprise
     * @param string $paymentMethods
     * @param FrontendInterface $cache
     * @param DirectoryList $directoryList
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly string $safeKey,
        private readonly string $username,
        private readonly string $password,
        private readonly string $environment,
        private readonly bool   $enterprise,
        private readonly string $paymentMethods,
        private readonly FrontendInterface $cache,
        private readonly DirectoryList $directoryList,
        private readonly SerializerInterface $serializer
    ) {
        $this->initApi();
    }

    /**
     * @return void
     */
    private function initApi(): void
    {
        if (!$this->apiContext) {
            $this->apiContext = new Context(
                new Authentication(
                    $this->username,
                    $this->password,
                    $this->safeKey
                )
            );

            try {
                $logFile = $this->directoryList->getPath('log') . DIRECTORY_SEPARATOR . 'payu_gateway.log';
            } catch (FileSystemException $exception) {
                $logFile = 'payu_gateway.log';
            }

            $this->apiContext->setConfig(
                [
                    'mode' => $this->environment,
                    'log.log_enabled' => $this->environment === 'sandbox',
                    'log.file_name' => $logFile,
                    'log.log_level' => 'DEBUG',
                    'cache.enabled' => true,
                    'default_account.payment_methods' => $this->paymentMethods
                ]
            );
        }

        $this->apiContext->setAccountId('default_account')
            ->setIntegration(
                $this->enterprise ?
                    Context::ENTERPRISE :
                    Context::REDIRECT
            );
    }

    /**
     * @param array $attributes
     * @return ResponseInterface
     */
    public function sale(array $attributes): ResponseInterface
    {
        return match ($this->enterprise) {
            true => $this->doEnterprise($attributes),
            false => $this->doRedirect($attributes)
        };
    }

    /**
     * @param array $attributes
     * @return Response
     */
    public function order(array $attributes): ResponseInterface
    {
        return $this->doRedirect($attributes);
    }

    /**
     * @param string $reference
     * @param string $orderId
     * @return Response
     * @throws LocalizedException
     */
    public function search(string $reference, string $orderId): ResponseInterface
    {
        $cacheKey = implode('_', [
            \PayU\Gateway\Model\Cache\Type::TYPE_IDENTIFIER,
            $reference,
            $orderId
        ]);
        $data = $this->cache->load($cacheKey);

        if (!$data) {
            $search = new Search();
            $search->setContext($this->apiContext)
                ->setPayUReference($reference);

            $response = Processor::processAction('search', $search);

            if (!$response->getResultCode()) {
                throw new LocalizedException(__('PayU Gateway error encountered.'));
            }

            $dataToCache = $response->toArray();
            $this->cache->save(
                $this->serializer->serialize($dataToCache),
                $cacheKey,
                [\PayU\Gateway\Model\Cache\Type::CACHE_TAG],
                3600 // 1 hour
            );
        } else {
            $data = $this->serializer->unserialize($data);
            $response = new Response($data);
        }

        return $response;
    }

    /**
     * @param array $attributes
     * @return Response
     * @throws LocalizedException
     */
    public function transactionInfo(array $attributes): Response
    {
        $payUReference = $attributes[TransactionInfoDataBuilder::PAYU_REFERENCE]
            ?? $attributes['payment']->getTransactionId()
            ?? $attributes['payment']->getLastTransId();

        $orderId = $attributes[TransactionInfoDataBuilder::ORDER_ID] ?? null;

        if (!$payUReference || !$orderId) {
            throw new LocalizedException(__("Invalid reference: $payUReference, order ID: $orderId"));
        }

        return $this->search($payUReference, $orderId);
    }

    /**
     * @param array $attributes
     * @return ResponseInterface
     */
    public function capture(array $attributes): ResponseInterface
    {
        $capture = new Capture();
        $capture->setContext($this->apiContext)
            ->setTransactionType(TransactionInterface::TYPE_FINALIZE)
            ->setCustomer($attributes[CaptureDataBuilder::CUSTOMER])
            ->setTransaction($attributes[CaptureDataBuilder::TRANSACTION])
            ->setPayUReference($attributes[CaptureDataBuilder::PAYU_REFERENCE])
            ->setMerchantReference($attributes[CaptureDataBuilder::MERCHANT_REFERENCE]);

        return Processor::processAction('capture', $capture);
    }

    /**
     * @param array $attributes
     * @return ResponseInterface
     */
    public function refund(array $attributes): ResponseInterface
    {
        $refund = new Refund();
        $refund->setContext($this->apiContext)
            ->setTransactionType(TransactionInterface::TYPE_CREDIT)
            ->setTransaction($attributes[RefundDataBuilder::TRANSACTION])
            ->setPayUReference($attributes[RefundDataBuilder::PAYU_REFERENCE])
            ->setMerchantReference($attributes[RefundDataBuilder::MERCHANT_REFERENCE]);

        return Processor::processAction('refund', $refund);
    }

    /**
     * @param array $attributes
     * @return ResponseInterface
     */
    public function void(array $attributes): ResponseInterface
    {
        $void = new VoidAuthorize();
        $void->setContext($this->apiContext)
            ->setTransactionType(TransactionInterface::TYPE_RESERVE_CANCEL)
            ->setTransaction($attributes[VoidDataBuilder::TRANSACTION])
            ->setPayUReference($attributes[VoidDataBuilder::PAYU_REFERENCE])
            ->setMerchantReference($attributes[VoidDataBuilder::MERCHANT_REFERENCE]);

        return Processor::processAction('void', $void);
    }

    /**
     * @param array $attributes
     * @return ResponseInterface
     */
    private function doEnterprise(array $attributes): ResponseInterface
    {
        $cart = new Cart();
        $sale = new Sale();
        $transaction = new Transaction();

        $basket = $attributes[BasketDataBuilder::BASKET];
        $itemList = $attributes[FraudDataBuilder::ITEM_LIST];
        $customer = $attributes[CustomerDataBuilder::CUSTOMER];
        $fraudManagement = $attributes[FraudDataBuilder::FRAUD];
        $shippingInfo = $attributes[AddressDataBuilder::SHIPPING_INFO];
        $fundingInstrument = $attributes[PaymentCardDetailsDataBuilder::CARD];

        if ($fundingInstrument) {
            $customer->setPaymentMethod(
                $attributes[AdditionalInfoDataBuilder::ADDITIONAL_INFO][AdditionalInfoDataBuilder::SUPPORTED_METHODS]
            );
            $customer->setFundingInstrument($fundingInstrument);
        }

        if ($fraudManagement && $itemList) {
            $cart->setItems($itemList);
            $cart->setTotal((float)$basket[BasketDataBuilder::AMOUNT]->getAmount());
            $transaction->setFraudService($fraudManagement);
        }

        $transaction->setTotal($basket[BasketDataBuilder::AMOUNT])
            ->setDescription($basket[BasketDataBuilder::DESCRIPTION])
            ->setReference($basket[BasketDataBuilder::MERCHANT_REFERENCE])
            ->setDemo($attributes[AdditionalInfoDataBuilder::ADDITIONAL_INFO][AdditionalInfoDataBuilder::DEMO_MODE]);

        if ($shippingInfo) {
            $transaction->setShippingInfo($shippingInfo);
        }

        $sale->setContext($this->apiContext)
            ->setTransactionType($attributes[TransactionTypeBuilder::TRANSACTION_TYPE])
            ->setCustomer($customer)
            ->setTransaction($transaction)
            ->setTransactionUrl($attributes[PaymentUrlDataBuilder::PAYMENT_URLS]);

        return Processor::processAction('sale', $sale);
    }

    /**
     * @param array $attributes
     * @return ResponseInterface
     */
    private function doRedirect(array $attributes): ResponseInterface
    {
        $cart = new Cart();
        $redirect = new Redirect();
        $transaction = new Transaction();

        $basket = $attributes[BasketDataBuilder::BASKET];
        $itemList = $attributes[FraudDataBuilder::ITEM_LIST];
        $fraudService = $attributes[FraudDataBuilder::FRAUD];
        $shippingInfo = $attributes[AddressDataBuilder::SHIPPING_INFO];

        if ($fraudService && $itemList) {
            $cart->setItems($itemList);
            $cart->setTotal((float)$basket[BasketDataBuilder::AMOUNT]->getAmount());
            $transaction->setFraudService($fraudService);
        }

        $transaction->setTotal($basket[BasketDataBuilder::AMOUNT])
            ->setDescription($basket[BasketDataBuilder::DESCRIPTION])
            ->setReference($basket[BasketDataBuilder::MERCHANT_REFERENCE])
            ->setDemo($attributes[AdditionalInfoDataBuilder::ADDITIONAL_INFO][AdditionalInfoDataBuilder::DEMO_MODE]);

        if ($shippingInfo) {
            $transaction->setShippingInfo($shippingInfo);
        }

        $redirect->setContext($this->apiContext)
            ->setTransactionType($attributes[TransactionTypeBuilder::TRANSACTION_TYPE])
            ->setCustomer($attributes[CustomerDataBuilder::CUSTOMER])
            ->setTransaction($transaction)
            ->setTransactionUrl($attributes[PaymentUrlDataBuilder::PAYMENT_URLS]);

        return Processor::processAction('setup', $redirect);
    }
}
