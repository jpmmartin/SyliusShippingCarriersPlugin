<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\EventListener;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\EventListener\KeepTheRecalculatedCartListener;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Bundle\ApiBundle\CommandHandler\Checkout\Exception\OrderTotalHasChangedException;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Registry\ServiceRegistry;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

/**
 * What the listener must never do: turn Sylius's refusal into a server error, or keep anything of the refused
 * confirmation but the recalculated cart.
 */
final class KeepTheRecalculatedCartListenerTest extends TestCase
{
    private RecordingLogger $logger;

    /** @var list<string> What was done to the database, in order */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->calls = [];
    }

    /**
     * The one that matters: a failure here, of the database or of anything else, leaves the refusal as Sylius
     * sent it — a 409 the buyer can act on — and says why in the log.
     */
    public function testAFailureKeepingTheCartLeavesTheRefusalAsItWas(): void
    {
        $event = $this->refusal();

        $this->listener(processorFails: true)($event);

        self::assertFalse($event->hasResponse(), 'The refusal is not replaced.');
        self::assertInstanceOf(OrderTotalHasChangedException::class, $event->getThrowable());
        self::assertSame(LogLevel::ERROR, $this->logger->records[0][0] ?? null);
    }

    /**
     * Whatever the refused confirmation left in memory is dropped before the cart is read again.
     */
    public function testTheCartIsReadAgainFromWhatIsStoredBeforeItIsRecalculated(): void
    {
        $this->listener()($this->refusal());

        self::assertSame(['find', 'clear', 'find', 'process', 'flush'], $this->calls);
    }

    /**
     * An order this plugin does not ship is left exactly as Sylius leaves it: its entity manager is not even
     * emptied.
     */
    public function testACartThePluginDoesNotShipIsNotTouchedAtAll(): void
    {
        $this->listener(cart: $this->cartShippedBy('flat_rate'))($this->refusal());

        self::assertSame(['find'], $this->calls);
    }

    public function testAnotherFailureIsNoneOfItsBusiness(): void
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $this->request(), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Something else.'));

        $this->listener()($event);

        self::assertSame([], $this->calls);
    }

    public function testTheSameRefusalOnAnotherRouteIsNoneOfItsBusiness(): void
    {
        $request = $this->request();
        $request->attributes->set('_route', 'sylius_api_shop_order_get');

        $this->listener()(new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new OrderTotalHasChangedException()));

        self::assertSame([], $this->calls);
    }

    private function listener(bool $processorFails = false, ?Order $cart = null): KeepTheRecalculatedCartListener
    {
        $cart ??= $this->cartShippedBy('ups_rate');

        $repository = $this->createStub(OrderRepositoryInterface::class);
        $repository->method('findCartByTokenValue')->willReturnCallback(function () use ($cart): Order {
            $this->calls[] = 'find';

            return $cart;
        });

        $processor = $this->createStub(OrderProcessorInterface::class);
        $processor->method('process')->willReturnCallback(function () use ($processorFails): void {
            $this->calls[] = 'process';
            if ($processorFails) {
                throw new \RuntimeException('The database went away.');
            }
        });

        $manager = $this->createStub(ObjectManager::class);
        $manager->method('clear')->willReturnCallback(function (): void {
            $this->calls[] = 'clear';
        });
        $manager->method('flush')->willReturnCallback(function (): void {
            $this->calls[] = 'flush';
        });

        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $calculators->register('ups_rate', new CarrierRateCalculator(new ShippingChargeResolver($this->createStub(RateProviderInterface::class)), 'ups', 'ups_rate'));

        return new KeepTheRecalculatedCartListener($repository, $processor, $manager, new ShipmentCarrier($calculators), $this->logger);
    }

    private function refusal(): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $this->request(), HttpKernelInterface::MAIN_REQUEST, new OrderTotalHasChangedException());
    }

    private function request(): Request
    {
        $request = new Request();
        $request->attributes->set('_route', 'sylius_api_shop_order_patch_complete');
        $request->attributes->set('tokenValue', 'the-cart-token');

        return $request;
    }

    private function cartShippedBy(string $calculator): Order
    {
        $method = new ShippingMethod();
        $method->setCalculator($calculator);

        $shipment = new Shipment();
        $shipment->setMethod($method);

        $cart = new Order();
        $cart->addShipment($shipment);

        return $cart;
    }
}
