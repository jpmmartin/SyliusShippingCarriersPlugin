<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Api\CommandHandler;

use JpmMartin\SyliusShippingCarriersPlugin\Api\Command\SetOrderDestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Api\CommandHandler\SetOrderDestinationTypeHandler;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestination;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\Factory;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Saying whether a parcel goes to a home or to a business changes what the carriers charge for it, so the
 * answer is not worth much until the cart has been priced again with it.
 *
 * In the shop that happens by itself, because the type travels in the same submission as the address. Through
 * the API nothing else would ask, and the buyer would go on being shown the price of the other kind of
 * address until something unrelated moved the cart along.
 */
final class SetOrderDestinationTypeHandlerTest extends TestCase
{
    private OrderInterface $cart;

    private ?CarrierOrderDestinationInterface $stored = null;

    /** @var list<string> What was done, in the order it was done. */
    private array $done = [];

    protected function setUp(): void
    {
        $this->cart = new Order();
        $this->stored = null;
        $this->done = [];
    }

    public function testTheCartIsPricedAgainWithTheTypeTheBuyerGave(): void
    {
        ($this->handler())(new SetOrderDestinationType('a-token', DestinationType::RESIDENTIAL));

        self::assertSame(DestinationType::RESIDENTIAL, $this->stored?->getType());
        self::assertContains('processed', $this->done);
    }

    /**
     * The order matters: priced first and told afterwards, the cart would be quoted with the type it had
     * before, which is the bug this exists to stop.
     */
    public function testTheTypeIsSavedBeforeTheCartIsPriced(): void
    {
        ($this->handler())(new SetOrderDestinationType('a-token', DestinationType::RESIDENTIAL));

        self::assertSame(['saved', 'processed'], $this->done);
    }

    /**
     * A buyer who changes their mind gets the same treatment; there is no second row to add, but there is a
     * new price to work out.
     */
    public function testChangingTheTypeAlreadyGivenPricesTheCartAgainToo(): void
    {
        $this->stored = new CarrierOrderDestination();
        $this->stored->setOrder($this->cart);
        $this->stored->setType(DestinationType::COMMERCIAL);

        ($this->handler())(new SetOrderDestinationType('a-token', DestinationType::RESIDENTIAL));

        self::assertSame(DestinationType::RESIDENTIAL, $this->stored->getType());
        self::assertSame(['processed'], $this->done, 'Nothing is added: the row was already there.');
    }

    private function handler(): SetOrderDestinationTypeHandler
    {
        /** @var OrderRepositoryInterface<OrderInterface>&Stub $orderRepository */
        $orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $orderRepository->method('findCartByTokenValue')->willReturn($this->cart);

        /** @var FactoryInterface<CarrierOrderDestinationInterface> $destinationFactory */
        $destinationFactory = new Factory(CarrierOrderDestination::class);

        return new SetOrderDestinationTypeHandler(
            $orderRepository,
            $this->destinationRepository(),
            $destinationFactory,
            $this->orderProcessor(),
        );
    }

    /**
     * @return RepositoryInterface<CarrierOrderDestinationInterface>&Stub
     */
    private function destinationRepository(): RepositoryInterface
    {
        /** @var RepositoryInterface<CarrierOrderDestinationInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturnCallback(fn (): ?CarrierOrderDestinationInterface => $this->stored);
        $repository->method('add')->willReturnCallback(function (object $destination): void {
            self::assertInstanceOf(CarrierOrderDestinationInterface::class, $destination);
            $this->stored = $destination;
            $this->done[] = 'saved';
        });

        return $repository;
    }

    private function orderProcessor(): OrderProcessorInterface
    {
        $processor = $this->createMock(OrderProcessorInterface::class);
        $processor->method('process')->willReturnCallback(function (BaseOrderInterface $order): void {
            self::assertSame($this->cart, $order);
            self::assertSame(
                DestinationType::RESIDENTIAL,
                $this->stored?->getType(),
                'The cart is priced with the type the buyer just gave, not the one it had.',
            );
            $this->done[] = 'processed';
        });

        return $processor;
    }
}
