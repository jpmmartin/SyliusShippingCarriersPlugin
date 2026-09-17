<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\FailurePolicy;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class CarrierRateConfigurationValidator extends ConstraintValidator
{
    /**
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        private readonly CarrierServices $services,
        private readonly ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($constraint, CarrierRateConfiguration::class);

        $configuration = is_array($value) ? $value : [];

        $service = $configuration[CarrierRateCalculator::SERVICE] ?? null;
        if (!is_string($service) || null === $this->services->name($constraint->carrier, $service)) {
            $this->context->buildViolation($constraint->serviceMessage)
                ->atPath(sprintf('[%s]', CarrierRateCalculator::SERVICE))
                ->addViolation()
            ;
        }

        // Left out, the policy is to hide the shipping method.
        $failurePolicy = $configuration[CarrierRateCalculator::FAILURE_POLICY] ?? FailurePolicy::HIDE;
        if (!in_array($failurePolicy, FailurePolicy::ALL, true)) {
            $this->context->buildViolation($constraint->failurePolicyMessage)
                ->atPath(sprintf('[%s]', CarrierRateCalculator::FAILURE_POLICY))
                ->addViolation()
            ;

            return;
        }

        if (FailurePolicy::FLAT !== $failurePolicy) {
            return;
        }

        // Every channel, as Sylius asks of its own flat rate: a channel added to the shipping method later is never
        // left without an amount.
        $flatAmounts = $configuration[CarrierRateCalculator::FLAT_AMOUNT] ?? [];
        foreach ($this->channelRepository->findAll() as $channel) {
            $channelCode = (string) $channel->getCode();
            $amount = is_array($flatAmounts) ? ($flatAmounts[$channelCode] ?? null) : null;
            if (is_int($amount) && $amount >= 0) {
                continue;
            }

            $this->context->buildViolation($constraint->flatAmountMessage)
                ->atPath(sprintf('[%s][%s]', CarrierRateCalculator::FLAT_AMOUNT, $channelCode))
                ->setParameter('{{ channel }}', (string) $channel->getName())
                ->addViolation()
            ;
        }
    }
}
