<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * A carrier could not be reached, or stopped answering, as PSR-18 reports it to an SDK.
 *
 * @internal
 */
final class CarrierNetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        \Throwable $previous,
        private readonly RequestInterface $request,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
