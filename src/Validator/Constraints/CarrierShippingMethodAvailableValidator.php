<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use Sylius\Bundle\ApiBundle\Command\Checkout\CompleteOrder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Shipping\Checker\Eligibility\ShippingMethodEligibilityCheckerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class CarrierShippingMethodAvailableValidator extends ConstraintValidator
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param ShippingMethodEligibilityCheckerInterface $carrierRateEligibilityChecker The plugin's checker, which
     *                                                                                 only judges carriers' methods
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ShippingMethodEligibilityCheckerInterface $carrierRateEligibilityChecker,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($constraint, CarrierShippingMethodAvailable::class);

        $order = $value instanceof CompleteOrder ? $this->orderRepository->findOneBy(['tokenValue' => $value->orderTokenValue]) : $value;
        if (!$order instanceof OrderInterface) {
            return;
        }

        foreach ($order->getShipments() as $shipment) {
            $method = $shipment->getMethod();
            if (!$method instanceof ShippingMethodInterface) {
                continue;
            }

            // A disabled method, or one outside the order's channel, is already rejected by Sylius with its own
            // message.
            $channel = $order->getChannel();
            if (!$method->isEnabled() || null === $channel || !$method->getChannels()->contains($channel)) {
                continue;
            }

            if ($this->carrierRateEligibilityChecker->isEligible($shipment, $method)) {
                continue;
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ shipping_method }}', (string) $method->getName())
                ->addViolation()
            ;
        }
    }
}
