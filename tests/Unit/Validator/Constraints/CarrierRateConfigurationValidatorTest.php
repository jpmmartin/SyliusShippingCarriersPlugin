<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\CarrierRateConfiguration;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\CarrierRateConfigurationValidator;
use PHPUnit\Framework\MockObject\Stub;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<CarrierRateConfigurationValidator>
 */
final class CarrierRateConfigurationValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): CarrierRateConfigurationValidator
    {
        /** @var ChannelRepositoryInterface<ChannelInterface>&Stub $channelRepository */
        $channelRepository = $this->createStub(ChannelRepositoryInterface::class);
        $channelRepository->method('findAll')->willReturn([$this->channel('WEB_US', 'Web US'), $this->channel('WEB_EU', 'Web EU')]);

        return new CarrierRateConfigurationValidator(
            new CarrierServices(['ups' => ['01' => 'UPS Next Day Air', '03' => 'UPS Ground'], 'fedex' => ['FEDEX_GROUND' => 'FedEx Ground']]),
            $channelRepository,
        );
    }

    public function testAServiceOfTheCarrierThatHidesOnFailurePasses(): void
    {
        $this->validator->validate(['service' => '03', 'failure_policy' => 'hide'], new CarrierRateConfiguration('ups'));

        $this->assertNoViolation();
    }

    /**
     * Without a policy the shipping method hides, so no flat amount is asked for.
     */
    public function testWithoutAPolicyNoFlatAmountIsNeeded(): void
    {
        $this->validator->validate(['service' => '03'], new CarrierRateConfiguration('ups'));

        $this->assertNoViolation();
    }

    public function testAFlatAmountForEveryChannelPasses(): void
    {
        $this->validator->validate(
            ['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => ['WEB_US' => 1200, 'WEB_EU' => 0]],
            new CarrierRateConfiguration('ups'),
        );

        $this->assertNoViolation();
    }

    public function testAServiceOfAnotherCarrierIsRejected(): void
    {
        $this->validator->validate(['service' => 'FEDEX_GROUND', 'failure_policy' => 'hide'], new CarrierRateConfiguration('ups'));

        $this->buildViolation('jpmmartin_carrier.shipping_method.service.invalid')
            ->atPath('property.path[service]')
            ->assertRaised()
        ;
    }

    public function testAMissingServiceIsRejected(): void
    {
        $this->validator->validate(['failure_policy' => 'hide'], new CarrierRateConfiguration('ups'));

        $this->buildViolation('jpmmartin_carrier.shipping_method.service.invalid')
            ->atPath('property.path[service]')
            ->assertRaised()
        ;
    }

    public function testAnUnknownPolicyIsRejected(): void
    {
        $this->validator->validate(['service' => '03', 'failure_policy' => 'free'], new CarrierRateConfiguration('ups'));

        $this->buildViolation('jpmmartin_carrier.shipping_method.failure_policy.invalid')
            ->atPath('property.path[failure_policy]')
            ->assertRaised()
        ;
    }

    public function testAFlatPolicyWithoutTheAmountOfAChannelIsRejectedOnThatChannel(): void
    {
        $this->validator->validate(
            ['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => ['WEB_US' => 1200, 'WEB_EU' => null]],
            new CarrierRateConfiguration('ups'),
        );

        $this->buildViolation('jpmmartin_carrier.shipping_method.flat_amount.required')
            ->atPath('property.path[flat_amount][WEB_EU]')
            ->setParameter('{{ channel }}', 'Web EU')
            ->assertRaised()
        ;
    }

    public function testANegativeFlatAmountIsRejected(): void
    {
        $this->validator->validate(
            ['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => ['WEB_US' => -1, 'WEB_EU' => 1100]],
            new CarrierRateConfiguration('ups'),
        );

        $this->buildViolation('jpmmartin_carrier.shipping_method.flat_amount.required')
            ->atPath('property.path[flat_amount][WEB_US]')
            ->setParameter('{{ channel }}', 'Web US')
            ->assertRaised()
        ;
    }

    private function channel(string $code, string $name): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName($name);

        return $channel;
    }
}
