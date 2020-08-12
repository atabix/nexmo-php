<?php
/**
 * Vonage Client Library for PHP
 *
 * @copyright Copyright (c) 2016 Vonage, Inc. (http://vonage.com)
 * @license   https://github.com/vonage/vonage-php/blob/master/LICENSE.txt MIT License
 */

namespace Vonage\Client\Request;

use Vonage\Client\Response\ResponseInterface;

interface WrapResponseInterface
{
    /**
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function wrapResponse(ResponseInterface $response);
}
