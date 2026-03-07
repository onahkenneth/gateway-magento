<?php
/**
 * Copyright © 2022 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Gateway\Validator\Adminhtml;

use PayU\Gateway\Gateway\Validator\DefaultResponseValidator;

/**
 * class ResponseValidator
 * @package PayU\Gateway\Gateway\Validator
 */
class ResponseValidator extends DefaultResponseValidator
{
    /**
     * @return array
     */
    protected function getResponseValidators(): array
    {
        return array_merge(
            parent::getResponseValidators(),
            [
                function ($response) {
                    $isValid = $response->getSuccessful() === true;
                    return [
                        $isValid,
                        [__(!$isValid ? $response->getResultMessage() : 'Transaction unsuccessful')],
                        [__('N/A')]
                    ];
                }
            ]
        );
    }
}
