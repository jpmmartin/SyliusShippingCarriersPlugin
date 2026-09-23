<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\ServerException;
use Saloon\Exceptions\Request\Statuses\ForbiddenException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Exceptions\Request\Statuses\TooManyRequestsException;
use Saloon\Exceptions\Request\Statuses\UnauthorizedException;
use Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException;

/**
 * Turns anything FedEx's SDK, Saloon or the JSON decoding can throw into the plugin's own exceptions.
 *
 * Shared by everything the plugin asks FedEx, so a new operation — a shipment, a cancellation — needs nothing
 * added here beyond saying what it was asking for.
 *
 * @internal
 */
final readonly class FedexErrorTranslator
{
    public function translate(\Throwable $exception, string $operation): CarrierException
    {
        return match (true) {
            $exception instanceof CarrierException => $exception,
            $exception instanceof UnauthorizedException,
            $exception instanceof ForbiddenException => new CarrierCredentialsException(sprintf('FedEx rejected the credentials: %s', $this->errors($exception)), 0, $exception),
            // The SDK itself holds back token requests past FedEx's published limits, before sending them.
            $exception instanceof RateLimitReachedException => new CarrierUnavailableException(sprintf('FedEx refused the request: %s', $exception->getMessage()), 0, $exception),
            $exception instanceof TooManyRequestsException,
            $exception instanceof RequestTimeOutException,
            $exception instanceof ServerException => new CarrierUnavailableException(sprintf('FedEx answered with HTTP %d: %s', $exception->getStatus(), $this->errors($exception)), 0, $exception),
            $exception instanceof RequestException => new CarrierRejectedRequestException(sprintf('FedEx rejected %s: %s', $operation, $this->errors($exception)), 0, $exception),
            $exception instanceof FatalRequestException => new CarrierUnavailableException(sprintf('FedEx could not be reached: %s', $exception->getMessage()), 0, $exception),
            default => new UnexpectedCarrierResponseException(sprintf('FedEx answered with something the plugin cannot read: %s', $exception->getMessage()), 0, $exception),
        };
    }

    private function errors(RequestException $exception): string
    {
        try {
            $errors = $exception->getResponse()->json('errors');
        } catch (\Throwable) {
            return $exception->getMessage();
        }

        if (!\is_array($errors) || [] === $errors) {
            return $exception->getMessage();
        }

        return implode(' - ', array_map(
            static fn (mixed $error): string => \is_array($error)
                ? sprintf('%s: %s', \is_string($error['code'] ?? null) ? $error['code'] : '', \is_string($error['message'] ?? null) ? $error['message'] : '')
                : '',
            $errors,
        ));
    }
}
