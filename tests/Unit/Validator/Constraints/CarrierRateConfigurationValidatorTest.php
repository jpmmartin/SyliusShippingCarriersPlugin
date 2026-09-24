<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\CarrierRateConfiguration;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\CarrierRateConfigurationValidator;
use PHPUnit\Framework\MockObject\Stub;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
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

        // The Web US channel adds the UPS service 02 on its shipping origin.
        $origin = new CarrierShippingOrigin();
        $origin->setChannel($this->channel('WEB_US', 'Web US'));
        $origin->setServices('ups', ['02' => 'UPS 2nd Day Air']);
        /** @var RepositoryInterface<CarrierShippingOriginInterface>&Stub $originRepository */
        $originRepository = $this->createStub(RepositoryInterface::class);
        $originRepository->method('findAll')->willReturn([$origin]);

        return new CarrierRateConfigurationValidator(
            new CarrierServices(['ups' => ['01' => 'UPS Next Day Air', '03' => 'UPS Ground'], 'fedex' => ['FEDEX_GROUND' => 'FedEx Ground']], $originRepository),
            $channelRepository,
        );
    }

    /**
     * A service one channel adds can be chosen for a method offered in that channel.
     */
    public function testAServiceAChannelAddsCanBeChosenForAMethodOfThatChannel(): void
    {
        $this->setObject($this->methodIn($this->channel('WEB_US', 'Web US')));

        $this->validator->validate(['service' => '02', 'failure_policy' => 'hide'], new CarrierRateConfiguration('ups'));

        $this->assertNoViolation();
    }

    /**
     * Offered in a channel that does not have the service too, the method is refused, naming that channel.
     */
    public function testAServiceMissingFromOneOfTheMethodsChannelsIsRefusedNamingIt(): void
    {
        $this->setObject($this->methodIn($this->channel('WEB_US', 'Web US'), $this->channel('WEB_EU', 'Web EU')));

        $this->validator->validate(['service' => '02', 'failure_policy' => 'hide'], new CarrierRateConfiguration('ups'));

        $this->buildViolation('jpmmartin_carrier.shipping_method.service.not_in_channel')
            ->atPath('property.path[service]')
            ->setParameter('{{ channel }}', 'Web EU')
            ->assertRaised()
        ;
    }

    /**
     * The configuration's services are in every channel's list.
     */
    public function testAServiceOfTheConfigurationIsInEveryChannel(): void
    {
        $this->setObject($this->methodIn($this->channel('WEB_US', 'Web US'), $this->channel('WEB_EU', 'Web EU')));

        $this->validator->validate(['service' => '03', 'failure_policy' => 'hide'], new CarrierRateConfiguration('ups'));

        $this->assertNoViolation();
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

    private function methodIn(ChannelInterface ...$channels): ShippingMethod
    {
        $method = new ShippingMethod();
        foreach ($channels as $channel) {
            $method->addChannel($channel);
        }

        return $method;
    }

    private function channel(string $code, string $name): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName($name);

        return $channel;
    }
}
