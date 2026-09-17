<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\CarrierShippingMethodAvailable;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\CarrierShippingMethodAvailableValidator;
use PHPUnit\Framework\MockObject\Stub;
use Sylius\Bundle\ApiBundle\Command\Checkout\CompleteOrder;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Shipping\Checker\Eligibility\ShippingMethodEligibilityCheckerInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<CarrierShippingMethodAvailableValidator>
 */
final class CarrierShippingMethodAvailableValidatorTest extends ConstraintValidatorTestCase
{
    private bool $eligible = true;

    private ?OrderInterface $storedOrder = null;

    protected function createValidator(): CarrierShippingMethodAvailableValidator
    {
        /** @var OrderRepositoryInterface<OrderInterface>&Stub $orderRepository */
        $orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $orderRepository->method('findOneBy')->willReturnCallback(fn (): ?OrderInterface => $this->storedOrder);

        $checker = $this->createStub(ShippingMethodEligibilityCheckerInterface::class);
        $checker->method('isEligible')->willReturnCallback(fn (): bool => $this->eligible);

        return new CarrierShippingMethodAvailableValidator($orderRepository, $checker);
    }

    public function testAnOrderWhoseShippingMethodsAreAvailablePasses(): void
    {
        $this->validator->validate($this->order(), new CarrierShippingMethodAvailable());

        $this->assertNoViolation();
    }

    public function testAnOrderInTheShopWithAnUnavailableCarrierMethodIsRejected(): void
    {
        $this->eligible = false;

        $this->validator->validate($this->order(), new CarrierShippingMethodAvailable());

        $this->buildViolation('jpmmartin_carrier.order.shipping_method_unavailable')
            ->setParameter('{{ shipping_method }}', 'UPS Ground')
            ->assertRaised()
        ;
    }

    public function testAnOrderCompletedThroughTheApiIsFoundByItsToken(): void
    {
        $this->eligible = false;
        $this->storedOrder = $this->order();

        $this->validator->validate(new CompleteOrder('TOKEN'), new CarrierShippingMethodAvailable());

        $this->buildViolation('jpmmartin_carrier.order.shipping_method_unavailable')
            ->setParameter('{{ shipping_method }}', 'UPS Ground')
            ->assertRaised()
        ;
    }

    /**
     * Sylius rejects a disabled shipping method, or one outside the order's channel, with its own message.
     */
    public function testAMethodSyliusAlreadyRejectsIsLeftToSylius(): void
    {
        $this->eligible = false;
        $disabled = $this->order(enabled: false);
        $outsideTheChannel = $this->order(inChannel: false);

        $this->validator->validate($disabled, new CarrierShippingMethodAvailable());
        $this->validator->validate($outsideTheChannel, new CarrierShippingMethodAvailable());

        $this->assertNoViolation();
    }

    public function testAnApiCompletionForAnUnknownOrderIsLeftToSylius(): void
    {
        $this->eligible = false;

        $this->validator->validate(new CompleteOrder('UNKNOWN'), new CarrierShippingMethodAvailable());

        $this->assertNoViolation();
    }

    private function order(bool $inChannel = true, bool $enabled = true): Order
    {
        $channel = new Channel();
        $channel->setCode('WEB');

        $method = new ShippingMethod();
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('UPS Ground');
        $method->setEnabled($enabled);
        if ($inChannel) {
            $method->addChannel($channel);
        }

        $order = new Order();
        $order->setChannel($channel);
        $shipment = new Shipment();
        $shipment->setMethod($method);
        $order->addShipment($shipment);

        return $order;
    }
}
