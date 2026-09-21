<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\EventListener;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use Sylius\Bundle\ApiBundle\CommandHandler\Checkout\Exception\OrderTotalHasChangedException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Lets a buyer confirm through the API an order whose total moved while they were confirming it.
 *
 * A carrier's rate is only kept for a while, so a buyer who takes their time can meet a new one when they
 * confirm. Sylius then refuses the order, which is right, but it refuses inside the transaction of its command
 * bus, which is undone: the total that was recalculated is thrown away with it, the order keeps showing the old
 * one, and every new attempt is refused again. In the shop this does not happen, because the shop saves the
 * order before sending the buyer back.
 *
 * So once the transaction is gone, the cart is recalculated and saved on its own. The refusal stays exactly as
 * Sylius sends it; what changes is that the next look at the order shows the new total, and the next attempt
 * is measured against it. Only carts this plugin ships are touched: any other order behaves as it would without
 * the plugin.
 */
final readonly class KeepTheRecalculatedCartListener
{
    /** Sylius's own name for confirming an order through the shop API. */
    private const COMPLETE_ORDER_ROUTE = 'sylius_api_shop_order_patch_complete';

    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private OrderProcessorInterface $orderProcessor,
        private ObjectManager $orderManager,
        private ShipmentCarrier $shipmentCarrier,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof OrderTotalHasChangedException) {
            return;
        }

        $request = $event->getRequest();
        $tokenValue = $request->attributes->get('tokenValue');
        if (self::COMPLETE_ORDER_ROUTE !== $request->attributes->get('_route') || !is_string($tokenValue)) {
            return;
        }

        $cart = $this->orderRepository->findCartByTokenValue($tokenValue);
        if (!$cart instanceof OrderInterface || !$this->isShippedByThisPlugin($cart)) {
            return;
        }

        $this->orderProcessor->process($cart);
        $this->orderManager->flush();
    }

    private function isShippedByThisPlugin(OrderInterface $cart): bool
    {
        foreach ($cart->getShipments() as $shipment) {
            if ($shipment instanceof ShipmentInterface && null !== $this->shipmentCarrier->of($shipment)) {
                return true;
            }
        }

        return false;
    }
}
