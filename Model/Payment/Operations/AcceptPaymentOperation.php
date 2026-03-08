<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Payment\Operations;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use PayU\Gateway\Model\Payment\AbstractOperation;

/**
 * class AcceptPaymentOperation
 * @package PayU\Gateway\Model\Payment\Operations
 */
class AcceptPaymentOperation extends AbstractOperation
{
    /**
     * @param OrderPaymentInterface $payment
     * @param ?string $comment
     * @return void
     * @throws LocalizedException
     */
    public function accept(OrderPaymentInterface $payment, ?string $comment = null): void
    {
        $isError = false;
        $transactionInfo = $payment->getTransactionAdditionalInfo()['transactionInfo'];

        $processId = $transactionInfo->getProcessId();
        $processClass = $transactionInfo->getProcessClass();
        $orderIncrementId = $transactionInfo->getMerchantReference();

        if ($orderIncrementId) {
            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            $orderPayment = $order->getPayment();
            $message = "The payment transaction didn't work out because we can't find order.";

            if (!$orderPayment || $orderPayment->getMethod() !== $payment->getMethodInstance()->getCode()) {
                throw new LocalizedException(
                    __($message)
                );
            }

            $orderId = $order->getId();

            if ((int)$orderId > 0 && $order->canInvoice()) {
                try {
                    $this->validator->validate($order, $transactionInfo);
                    $this->fraudOperation->fraud($payment, $transactionInfo);

                    $order = $this->invoiceOperation->invoice($order, $transactionInfo);

                    $this->transactionOperation->update($order, $transactionInfo);

                    $this->updatePayment($order, $transactionInfo);
                    $this->addStatusCommentOnUpdate($order, $payment, $transactionInfo);

                    if ($comment) {
                        $order->addCommentToStatusHistory($comment, 'processing');
                    }

                    $this->orderRepository->save($order);
                } catch (LocalizedException|Exception $exception) {
                    $isError = true;
                    $this->logger->error(
                        $exception->getMessage(),
                        [
                            'error' => "order ID => ($orderIncrementId) ($processId) ($processClass)" . PHP_EOL,
                            "Stack trace:" . PHP_EOL => $exception->getTraceAsString()
                        ]
                    );
                }
            } else {
                $isError = true;
            }
        } else {
            $isError = true;
        }

        if ($isError) {
            $responseText = "The payment transaction didn't work out. Error encountered while capturing your payment";
            $responseText = !$transactionInfo->isPaymentComplete()
                ? __($transactionInfo->getResultMessage())
                : __($responseText);

            throw new LocalizedException($responseText);
        }
    }
}
