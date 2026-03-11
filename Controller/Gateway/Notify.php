<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Controller\Gateway;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use PayU\Gateway\Controller\AbstractAction;
use PayUSdk\Framework\XMLHelper;

/**
 * class Notify
 * @package PayU\Gateway\Controller\Gateway
 */
class Notify extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Process Instant Payment Notification (IPN) from PayU
     * @throws LocalizedException
     */
    public function execute(): Json
    {
        $processId = uniqid();
        $processClass = self::class;

        $postData = file_get_contents("php://input");
        $sxe = simplexml_load_string($postData);

        $resultJson = $this->resultFactory
            ->create(ResultFactory::TYPE_JSON)
            ->setJsonData('{}');

        if (!$sxe) {
            $this->respond('500', 'Instant Payment Notification data is empty');

            return $resultJson;
        }

        $ipnData = XMLHelper::parseXMLToArray($sxe);

        if (!$ipnData) {
            $this->respond('500', 'Failed to decode Instant Payment Notification data.');

            return $resultJson;
        }

        $incrementId = $ipnData->MerchantReference;
        $canProceed = $this->responseProcessor->canProceed(
            $incrementId,
            $processId,
            $processClass
        );

        if (!$canProceed) {
            $this->respond('200', 'OK');

            return $resultJson;
        }

        $this->logger->debug([
            'info' => "START processing ($incrementId) => ($processId) ($processClass)"
        ]);

        $order = $incrementId ? $this->orderFactory->create()->loadByIncrementIdAndStoreId(
            $incrementId,
            $this->storeManager->getStore()->getId()
        ) : false;

        if (!$order || ((int)$order->getId() <= 0)) {
            $this->respond('500', 'Failed to load order.');

            return $resultJson;
        }

        $this->respond('200', 'OK');

        try {
            $this->responseProcessor->notify($order, $ipnData, $processId, $processClass);
        } catch (LocalizedException $ex) {
            $this->responseProcessor->updateTransactionLog($incrementId, $processId, 'error');

            return $resultJson;
        }

        $this->responseProcessor->updateTransactionLog($incrementId, $processId);

        return $resultJson;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
}
