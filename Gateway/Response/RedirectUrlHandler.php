<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Response;

use Magento\Framework\Session\Generic;
use Magento\Payment\Gateway\Response\HandlerInterface;
use PayU\Gateway\Gateway\SubjectReader;

/**
 * class RedirectUrlHandler
 * @package PayU\Gateway\Gateway\Response
 */
class RedirectUrlHandler implements HandlerInterface
{
    /**
     * @param SubjectReader $subjectReader
     * @param Generic $payuSession
     */
    public function __construct(
        private readonly SubjectReader $subjectReader,
        private readonly Generic $payuSession
    ) {
    }

    /**
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $responseObj = $this->subjectReader->readResponse($response);

        if (!$responseObj) {
            return;
        }

        $paymentDO = $this->subjectReader->readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();

        $payment->setIsTransactionPending(true);
        $payUReference = $responseObj->getPayUReference();
        $payment->setAdditionalInformation('payUReference', $payUReference);

        $message = 'Redirecting to PayU Gateway. <br />PayU Reference: "%1"';
        $message = __(
            $message,
            $payUReference
        );

        $order->addStatusHistoryComment($message);

        $this->payuSession->unsCheckoutRedirectUrl();
        $this->payuSession->setCheckoutReference($payUReference);
        $this->payuSession->setCheckoutRedirectUrl($responseObj->getPayURedirectUrl());
    }
}
