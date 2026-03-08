<?php
/**
 * Copyright © 2026 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace PayU\Gateway\Model\Cache;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

class Type extends TagScope
{
    /**
     * Type Code for Cache. It should be unique
     */
    const TYPE_IDENTIFIER = 'payu_gateway';

    /**
     * Tag of Cache
     */
    const CACHE_TAG = 'PAYU_GATEWAY';

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(
        FrontendPool $cacheFrontendPool
    ) {
        parent::__construct(
            $cacheFrontendPool->get(self::TYPE_IDENTIFIER),
            self::CACHE_TAG
        );
    }
}
