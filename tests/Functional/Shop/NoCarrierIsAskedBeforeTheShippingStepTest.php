<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;

/**
 * Every rate costs the merchant a call to a carrier, so none may go out before the buyer reaches the step
 * where the prices are shown. Filling a basket and typing an address must cost nothing.
 *
 * The shipping step itself does quote — the cart has no method there either, because choosing one is what the
 * buyer is about to do — and that is the whole point of quoting. What this pins is that nothing runs ahead of
 * it. How many calls the step itself makes is another promise, proved elsewhere.
 */
final class NoCarrierIsAskedBeforeTheShippingStepTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private FakeCarrierState $ups;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();
        $this->client->followRedirects();
        $this->replaceCredentialsProvider();

        $ups = self::getContainer()->get('jpmmartin_carrier.behat.fake_carrier_state');
        self::assertInstanceOf(FakeCarrierState::class, $ups);
        $this->ups = $ups;
        $this->ups->reset();
        // A carrier with a rate to give, so nothing is quiet merely because there was nothing to say.
        $this->ups->rateService('ups', '03', 1540, 'USD');

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();

        $this->createStore();
        $this->createOrigin();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testTypingAnAddressAsksNoCarrier(): void
    {
        $this->createCartInSession(OrderCheckoutStates::STATE_CART);
        $this->forgetTheStoredRates();
        $this->ups->forgetCalls();

        $this->client->request('GET', '/en_US/checkout/address');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->ups->calls('ups'), 'A cart on its way to the shipping step cost the merchant a call.');
    }

    /**
     * The other half, so the first does not pass merely because the carrier was never reachable: one step
     * further along, the same set-up does ask.
     */
    public function testTheShippingStepDoesAsk(): void
    {
        $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);
        $this->forgetTheStoredRates();
        $this->ups->forgetCalls();

        $this->client->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->ups->calls('ups'));
    }

    /**
     * Both the stored rates and the services that remember a carrier was already asked, reset the way they are
     * between two requests. Otherwise a page could look quiet only because an earlier one had asked.
     */
    private function forgetTheStoredRates(): void
    {
        $rates = self::getContainer()->get('jpmmartin_carrier.cache.rates');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rates);
        $rates->clear();

        $servicesResetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetterInterface::class, $servicesResetter);
        $servicesResetter->reset();
    }
}
