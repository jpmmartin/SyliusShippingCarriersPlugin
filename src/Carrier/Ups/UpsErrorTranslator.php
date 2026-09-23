<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use Psr\Http\Client\ClientExceptionInterface;
use ShipStream\Ups\Api\Exception\BadRequestException;
use ShipStream\Ups\Api\Exception\ForbiddenException;
use ShipStream\Ups\Api\Exception\GenerateTokenBadRequestException;
use ShipStream\Ups\Api\Exception\GenerateTokenForbiddenException;
use ShipStream\Ups\Api\Exception\GenerateTokenTooManyRequestsException;
use ShipStream\Ups\Api\Exception\GenerateTokenUnauthorizedException;
use ShipStream\Ups\Api\Exception\TooManyRequestsException;
use ShipStream\Ups\Api\Exception\UnauthorizedException;
use ShipStream\Ups\Api\Exception\UnexpectedStatusCodeException;
use ShipStream\Ups\Api\Model\Error;
use ShipStream\Ups\Exception\AuthenticationException;

/**
 * Turns anything UPS's SDK, its HTTP client or its serializer can throw into the plugin's own exceptions.
 *
 * It matches on the SDK's base exceptions rather than on the per-operation ones, so asking UPS something new —
 * a shipment, a void, a label recovery — needs nothing added here. The token exceptions are the exception to
 * that: a rejected token is bad credentials, not a bad request.
 *
 * @internal
 */
final readonly class UpsErrorTranslator
{
    /**
     * @param string $operation What was being asked of UPS, to say so in the message: «the rate request»,
     *                          «the shipment request»
     */
    public function translate(\Throwable $exception, string $operation): CarrierException
    {
        return match (true) {
            $exception instanceof CarrierException => $exception,
            $exception instanceof GenerateTokenBadRequestException,
            $exception instanceof GenerateTokenUnauthorizedException,
            $exception instanceof GenerateTokenForbiddenException => new CarrierCredentialsException(sprintf('UPS rejected the credentials: %s', $this->errors($exception)), 0, $exception),
            $exception instanceof GenerateTokenTooManyRequestsException => new CarrierUnavailableException('UPS refused the request: too many requests.', 0, $exception),
            $exception instanceof UnauthorizedException,
            $exception instanceof ForbiddenException => new CarrierCredentialsException(sprintf('UPS rejected the credentials: %s', $this->errors($exception)), 0, $exception),
            $exception instanceof BadRequestException => new CarrierRejectedRequestException(sprintf('UPS rejected %s: %s', $operation, $this->errors($exception)), 0, $exception),
            $exception instanceof TooManyRequestsException => new CarrierUnavailableException('UPS refused the request: too many requests.', 0, $exception),
            // The SDK wraps whatever failed while getting the access token; the cause decides.
            $exception instanceof AuthenticationException => null !== $exception->getPrevious()
                ? $this->translate($exception->getPrevious(), $operation)
                : new CarrierCredentialsException(sprintf('UPS did not grant an access token: %s', $exception->getMessage()), 0, $exception),
            $exception instanceof UnexpectedStatusCodeException => $exception->getCode() >= 500
                ? new CarrierUnavailableException(sprintf('UPS answered with HTTP %d.', $exception->getCode()), 0, $exception)
                : new UnexpectedCarrierResponseException(sprintf('UPS answered with an unexpected HTTP %d.', $exception->getCode()), 0, $exception),
            $exception instanceof ClientExceptionInterface => new CarrierUnavailableException(sprintf('UPS could not be reached: %s', $exception->getMessage()), 0, $exception),
            default => new UnexpectedCarrierResponseException(sprintf('UPS answered with something the plugin cannot read: %s', $exception->getMessage()), 0, $exception),
        };
    }

    /**
     * What UPS said, when it said anything in the shape the SDK expects. Every operation carries its errors
     * the same way, but on its own exception class, so this asks the object rather than naming them all.
     */
    private function errors(\Throwable $exception): string
    {
        try {
            if (!method_exists($exception, 'getErrorResponse')) {
                return $exception->getMessage();
            }

            /** @var list<Error> $errors */
            $errors = $exception->getErrorResponse()->getResponse()->getErrors();

            return implode(' - ', array_map(
                static fn (Error $error): string => sprintf('%s: %s', $error->getCode(), $error->getMessage()),
                $errors,
            ));
        } catch (\Throwable) {
            // An error body without the expected shape still leaves the status behind the message.
            return $exception->getMessage();
        }
    }
}
