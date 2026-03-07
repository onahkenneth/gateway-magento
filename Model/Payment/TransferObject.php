<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Payment;

use Magento\Framework\Convert\ConvertArray;
use Magento\Framework\DataObject;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use PayU\Gateway\Model\Constants\TransactionState;
use PayU\Gateway\Model\Payment\Method\Masterpass;
use PayU\Gateway\Model\Payment\Method\Payflex;

/**
 * class TransferObject
 * @package PayU\Gateway\Model\Payment
 */
class TransferObject extends DataObject
{
    /**
     * @param array $data
     */
    public function __construct(
        array $data = []
    ) {
        parent::__construct($data);
    }

    /**
     * @return bool
     */
    public function isPaymentComplete(): bool
    {
        return $this->_getData('txn')->successful
            && $this->getTransactionState() === TransactionState::SUCCESSFUL->value;
    }

    /**
     * @return bool
     */
    public function isAwaitingPayment(): bool
    {
        return $this->_getData('txn')->successful
            && $this->getTransactionState() === TransactionState::AWAITING_PAYMENT->value;
    }

    /**
     * @return bool
     */
    public function isPaymentProcessing(): bool
    {
        return $this->_getData('txn')->successful
            && $this->getTransactionState() === TransactionState::PROCESSING->value;
    }

    /**
     * @return bool
     */
    public function isPaymentNew(): bool
    {
        return $this->_getData('txn')->successful
            && $this->getTransactionState() === TransactionState::NEW->value;
    }

    /**
     * @return bool
     */
    public function isPaymentFailed(): bool
    {
        return ($this->_getData('txn')->successful === true || $this->_getData('txn')->successful === false)
            && in_array(
                $this->getTransactionState(),
                [TransactionState::FAILED, TransactionState::EXPIRED, TransactionState::TIMEOUT]
            );
    }

    /**
     * @return string
     */
    public function getTranxId(): string
    {
        return $this->_getData('txn')->payUReference;
    }

    /**
     * @return string
     */
    public function getPayUReference(): string
    {
        return $this->getTranxId();
    }

    /**
     * @return string
     */
    public function getResultCode(): string
    {
        return $this->_getData('txn')->resultCode;
    }

    /**
     * @return string
     */
    public function getResultMessage(): string
    {
        return $this->_getData('txn')->resultMessage;
    }

    /**
     * @return bool
     */
    public function hasPaymentMethod(): bool
    {
        return isset($this->_getData('txn')->paymentMethodsUsed);
    }

    public function getPaymentMethods()
    {
        return $this->hasPaymentMethod() ? $this->_getData('txn')->paymentMethodsUsed : null;
    }

    /**
     * @return bool
     */
    public function hasCreditCard(): bool
    {
        return $this->isPaymentMethodCc();
    }

    /**
     * @return bool
     */
    public function isPaymentMethodCc(): bool
    {
        return $this->hasPaymentMethod() && $this->checkPaymentMethodCc();
    }

    public function checkPaymentMethodCc(): bool
    {
        $paymentMethods = $this->getPaymentMethods();

        if (is_array($paymentMethods)) {
            foreach ($paymentMethods as $method) {
                if (property_exists($method, 'gatewayReference')) {
                    return true;
                }
            }
        } else {
            if (property_exists($paymentMethods, 'gatewayReference')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function getGatewayReference(): string
    {
        $gatewayReference = 'N/A';
        $paymentMethods = $this->getPaymentMethods();

        if (is_array($paymentMethods)) {
            foreach ($paymentMethods as $method) {
                if (property_exists($method, 'gatewayReference')) {
                    $gatewayReference = $method->gatewayReference;
                }
            }
        } else {
            if (property_exists($paymentMethods, 'gatewayReference')) {
                $gatewayReference = $paymentMethods->gatewayReference;
            }
        }

        return $gatewayReference;
    }

    /**
     * @return array
     */
    public function getCardData(): array
    {
        $cardData = [];
        $hasCc = $this->isPaymentMethodCc();

        if ($hasCc) {
            $paymentMethods = $this->getPaymentMethods();

            if (is_array($paymentMethods)) {
                foreach ($paymentMethods as $method) {
                    if (property_exists($method, 'cardNumber')) {
                        $cardData['cardNumber'] = $method->cardNumber;
                        $cardData['owner'] = $method->nameOnCard;
                        $cardData['txnId'] = $method->gatewayReference;
                        $cardData['expiryYear'] = substr($method->cardExpiry, -4);
                        $cardData['type'] = $method->information;
                    }
                }
            } else {
                if (property_exists($paymentMethods, 'cardNumber')) {
                    $cardData['cardNumber'] = $paymentMethods->cardNumber;
                    $cardData['owner'] = $paymentMethods->nameOnCard;
                    $cardData['txnId'] = $paymentMethods->gatewayReference;
                    $cardData['expiryYear'] = substr($paymentMethods->cardExpiry, -4);
                    $cardData['type'] = $paymentMethods->information;
                }
            }
        }

        return $cardData;
    }

    /**
     * @return float
     */
    public function getTotalDue(): float
    {
        $basket = $this->getBasket();

        return $basket->amountInCents / 100 ?? 0;
    }

    /**
     * @return float
     */
    public function getTotalCaptured(): float
    {
        $total = 0.0;

        if ($this->isPaymentNew()) {
            return $total;
        }

        $paymentMethods = $this->getPaymentMethods();

        if (!$paymentMethods) {
            return $total;
        }

        if (
            is_a($paymentMethods, \stdClass::class, true) &&
            !property_exists($paymentMethods, 'amountInCents')
        ) {
            return $total;
        }

        if (
            is_a($paymentMethods, \stdClass::class, true) &&
            property_exists($paymentMethods, 'amountInCents')
        ) {
            return ($paymentMethods->amountInCents / 100);
        }

        foreach ($paymentMethods as $paymentMethod) {
            $total += $paymentMethod->amountInCents;
        }

        // Prevent division by zero
        return (max($total, 1) / 100);
    }

    /**
     * @return string
     */
    public function getDisplayMessage(): string
    {
        return $this->getData('txn')->displayMessage;
    }

    /**
     * @return bool
     */
    public function isFraudDetected(): bool
    {
        return isset($this->getData('txn')->fraud) && isset($this->getData('txn')->fraud->resultCode);
    }

    /**
     * @return string
     */
    public function getMerchantReference(): string
    {
        return $this->getData('txn')->merchantReference;
    }

    /**
     * @return string
     */
    public function getTransactionState(): string
    {
        return $this->getData('txn')->transactionState;
    }

    /**
     * @return string
     */
    public function getTransactionType(): string
    {
        return $this->getData('txn')->transactionType;
    }

    /**
     * @return mixed|null
     */
    public function getPointOfFailure(): mixed
    {
        return $this->getData('txn')->pointOfFailure;
    }

    /**
     * @return mixed|null
     */
    public function getBasket(): mixed
    {
        return $this->getData('txn')->basket;
    }

    /**
     * Transfer transaction/payment information from API to order payment
     * @param InfoInterface $to
     * @return void
     */
    public function importTransactionInfo(InfoInterface $to): void
    {
        /**
         * Detect payment review and/or frauds
         */
        if ($this->isFraudDetected()) {
            $to->setIsTransactionPending(true);
            $to->setIsFraudDetected(true);
        }

        // give generic info about transaction state
        if ($this->isPaymentComplete()) {
            $to->setIsTransactionApproved(true);
        } elseif ($this->isAwaitingPayment()) {
            $to->setIsTransactionPending(true);
        } elseif ($this->isPaymentProcessing()) {
            $to->setIsTransactionProcessing(true);
        } elseif ($this->isPaymentNew()) {
            $to->setIsTransactionPending(true);
        } else {
            $to->setIsTransactionDenied(true);
        }

        $to->setTransactionAdditionalInfo('transactionInfo', $this);

        if ($to->getCaptureOperationCalled()) {
            $to->setTransactionAdditionalInfo('transactionInfo', []);
            $to->setTransactionAdditionalInfo(
                Order\Payment\Transaction::RAW_DETAILS,
                $this->getPaymentData()
            );
        }
    }

    /**
     * @return array
     */
    public function getPaymentData(): array
    {
        return ConvertArray::toFlatArray(
            json_decode(
                json_encode(
                    $this->toArray()['txn']
                ), true
            )
        );
    }

    /**
     * Is Canceled Payflex transaction
     *
     * @param Order $order
     * @return bool
     */
    public function isCancelPayflex(Order $order)
    {
        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();

        return $method->getCode() === Payflex::CODE && ($this->isPaymentProcessing() || $this->isPaymentFailed());
    }

    /**
     * Is Canceled Payflex transaction
     *
     * @param Order $order
     * @return bool
     */
    public function isMasterpassTimeout(Order $order)
    {
        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();

        return $method->getCode() === Masterpass::CODE && ($this->isPaymentProcessing() || $this->isPaymentFailed());
    }
}
