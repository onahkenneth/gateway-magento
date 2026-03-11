<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Controller\Gateway;

use Exception;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use PayU\Gateway\Controller\AbstractAction;
use PayU\Gateway\Model\Constants\RedirectPage;
use PayU\Gateway\Model\Constants\TransactionState;

/**
 * class Response
 * @package PayU\Gateway\Controller\Gateway
 */
class Response extends AbstractAction implements HttpGetActionInterface, HttpPostActionInterface
{
    /**
     * Retrieve transaction information and validates payment
     *
     * @return ResponseInterface|Redirect|ResultInterface
     */
    public function execute()
    {
        $processId = uniqid();
        $processClass = self::class;
        $alreadyProcessed = false;

        $orderId = $this->getCheckoutSession()->getLastRealOrderId() ??
            $this->getCheckoutSession()->getData('last_real_order_id');

        if (!$orderId) {
            return $this->returnToCart();
        }

        try {
            $payUReference = $this->getPayUReference();

            $canProceed = $this->responseProcessor->canProceed(
                $orderId,
                $processId,
                $processClass
            );

            if (!$canProceed) {
                $page = $this->responseProcessor->redirectTo(
                    $orderId,
                    $payUReference
                );

                switch ($page) {
                    case RedirectPage::SUCCESS_PAGE->value:
                        return $this->sendSuccessPage();
                    case RedirectPage:: FAILED_PAGE->value:
                        return $this->sendFailedPage();
                    case RedirectPage:: RETURN_CART->value:
                        $this->messageManager->addErrorMessage('User canceled transaction');
                        return $this->returnToCart();
                    default:
                        return $this->sendPendingPage();
                }
            }

            $this->logger->debug(['info' => "START ($orderId) ($processId) $processClass"]);

            $order = $this->orderFactory->create()->loadByIncrementId($orderId);

            if (!$order) {
                return $this->sendFailedPage('Order not found');
            }

            $orderState = $order->getState();
            $orderStatus = $order->getStatus();

            // If the order is already a success
            if (
                $order->hasInvoices() ||
                in_array(
                    $orderState,
                    [
                        Order::STATE_PROCESSING,
                        Order::STATE_COMPLETE
                    ]
                ) ||
                in_array(
                    $orderStatus,
                    [
                        Order::STATE_PROCESSING,
                        Order::STATE_COMPLETE
                    ]
                )
            ) {
                $alreadyProcessed = true;
            }

            $bypassPayURedirect = (bool)$this->getRedirectConfigData('enable');

            if ($bypassPayURedirect) {
                $this->logger->debug(
                    ['info' => "($orderId) ($processId) $processClass: PayU redirect disabled, checking existing IPN status"]
                );

                // If the order is already a success
                if ($alreadyProcessed) {
                    $this->logger->debug([
                        'info' => "($orderId) ($processId)  $processClass:  already successful"
                    ]);
                    $this->responseProcessor->updateTransactionLog($orderId, $processId);

                    return $this->sendSuccessPage();
                }

                // Or still pending
                if (in_array(
                    $orderState,
                    [
                        Order::STATE_PENDING_PAYMENT,
                        TransactionState::MAGENTO_ORDER_STATE_PENDING
                    ]
                )) {
                    $this->logger->debug(['info' => " ($orderId) ($processId) $processClass order status pending"]);
                    $this->responseProcessor->updateTransactionLog($orderId, $processId);

                    return $this->sendPendingPage();
                }

                return $this->sendFailedPage();
            }

            $this->logger->debug(
                ['info' => "($orderId) ($processId) $processClass: PayU redirect enabled, processing redirect response."]
            );

            if ($alreadyProcessed) {
                $this->logger->debug([
                    'info' => "($orderId) ($processId) $processClass: already successful"
                ]);
                $this->responseProcessor->updateTransactionLog($orderId, $processId);

                return $this->sendSuccessPage();
            }

            if ($payUReference) {
                $successful = $this->responseProcessor->return(
                    $order,
                    $payUReference,
                    $processId,
                    $processClass
                );

                $this->responseProcessor->updateTransactionLog($orderId, $processId);

                if ($successful[0]) {
                    return $this->sendSuccessPage();
                }

                $message = $successful[1];

                if (
                    $this->responseProcessor->isCancelPayflex($order) ||
                    $this->responseProcessor->isMasterpassTimeout($order)
                ) {
                    $this->messageManager->addErrorMessage($message);

                    return $this->returnToCart();
                }

                if ($this->responseProcessor->isPaymentPending() || $this->responseProcessor->isPaymentProcessing()) {
                    $this->messageManager->addNoticeMessage($message);

                    return $this->sendPendingPage();
                }

                if ($this->responseProcessor->isPaymentFailed()) {
                    return $this->sendFailedPage($message);
                }
            }
        } catch (LocalizedException|Exception $exception) {
            $this->logger->debug([
                'error' => "($processId) ($orderId) $processClass" . $exception->getMessage()
            ]);
            $this->messageManager->addExceptionMessage(
                $exception,
                __($exception->getMessage())
            );
            $this->clearSessionData();
        }

        $this->responseProcessor->updateTransactionLog($orderId, $processId);

        return $this->returnToCart();
    }
}
