<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;

/**
 * Whatever goes wrong at UPS, the buyer never gets a server error in the cart or the checkout, whether the shipping
 * method hides or falls back on a flat amount. Everything is real but UPS.
 */
final class CarrierFailureDoesNotBreakCheckoutTest extends WebTestCase
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
        $this->replaceCredentialsProvider();

        $ups = self::getContainer()->get('jpmmartin_carrier.behat.fake_carrier_state');
        self::assertInstanceOf(FakeCarrierState::class, $ups);
        $this->ups = $ups;
        $this->ups->reset();

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

        $this->ups->reset();

        parent::tearDown();
    }

    /**
     * @param FakeCarrierState::FAILURE_* $failure
     */
    #[DataProvider('failures')]
    public function testTheCartAndTheCheckoutKeepWorkingWhenUpsFails(string $failure, string $policy): void
    {
        $this->upsGround->setConfiguration(['service' => '03', 'failure_policy' => $policy, 'flat_amount' => [self::CHANNEL => 1200]]);
        $this->entityManager->flush();

        // The buyer got as far as confirming while UPS answered; then UPS fails, with no rate kept to fall back on.
        $this->ups->rateService('ups', '03', 1540, 'USD');
        $this->createCartInSession(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        $this->ups->fail('ups', $failure);
        $this->startAsANewVisit();

        $this->visit('sylius_shop_cart_summary');
        $this->visit('sylius_shop_checkout_address');
        $this->submitIfThereIsAForm($this->visit('sylius_shop_checkout_select_shipping'), 'sylius_shop_checkout_select_shipping');
        $this->visit('sylius_shop_checkout_select_payment');
        $this->submitIfThereIsAForm($this->visit('sylius_shop_checkout_complete'), 'sylius_checkout_complete');

        self::assertGreaterThan(0, $this->ups->calls('ups'), 'UPS was never asked, so its failure was never met.');
    }

    /**
     * The key was rotated, lost, or the database came from another installation: the store cannot read the
     * credentials it stored. For the buyer that is UPS being unavailable, never a server error — and UPS is not
     * asked at all, because what would be sent as the client id is ciphertext.
     */
    #[DataProvider('policies')]
    public function testTheCartAndTheCheckoutKeepWorkingWhenTheStoredCredentialsCannotBeDecrypted(string $policy): void
    {
        $this->upsGround->setConfiguration(['service' => '03', 'failure_policy' => $policy, 'flat_amount' => [self::CHANNEL => 1200]]);
        $this->entityManager->flush();

        $this->ups->rateService('ups', '03', 1540, 'USD');
        $this->createCartInSession(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        $this->credentialsCannotBeDecrypted = true;
        $this->startAsANewVisit();
        $callsBefore = $this->ups->calls('ups');

        $this->visit('sylius_shop_cart_summary');
        $this->visit('sylius_shop_checkout_address');
        $this->submitIfThereIsAForm($this->visit('sylius_shop_checkout_select_shipping'), 'sylius_shop_checkout_select_shipping');
        $this->visit('sylius_shop_checkout_select_payment');
        $this->submitIfThereIsAForm($this->visit('sylius_shop_checkout_complete'), 'sylius_checkout_complete');

        self::assertSame($callsBefore, $this->ups->calls('ups'), 'UPS must not be asked with credentials the store cannot read.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function policies(): iterable
    {
        yield 'the method hides' => ['hide'];
        yield 'the method falls back on a flat amount' => ['flat'];
    }

    /**
     * @return iterable<string, array{FakeCarrierState::FAILURE_*, string}>
     */
    public static function failures(): iterable
    {
        foreach (['hide', 'flat'] as $policy) {
            yield sprintf('UPS does not answer in time, %s', $policy) => [FakeCarrierState::FAILURE_TIMEOUT, $policy];
            yield sprintf('UPS answers with a server error, %s', $policy) => [FakeCarrierState::FAILURE_SERVER_ERROR, $policy];
            yield sprintf('UPS answers with something unreadable, %s', $policy) => [FakeCarrierState::FAILURE_UNREADABLE, $policy];
            yield sprintf('UPS rejects the credentials, %s', $policy) => [FakeCarrierState::FAILURE_CREDENTIALS, $policy];
        }
    }

    private function visit(string $route): Crawler
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate($route, ['_locale' => 'en_US']));
        $this->assertNoServerError($route);

        return $crawler;
    }

    private function submitIfThereIsAForm(Crawler $page, string $formName): void
    {
        $form = $page->filter(sprintf('form[name="%s"]', $formName));
        if (0 === $form->count()) {
            return;
        }

        $this->client->submit($form->form());
        $this->assertNoServerError($formName);
    }

    private function assertNoServerError(string $what): void
    {
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode(), sprintf('%s answered with a server error.', $what));
    }

    /**
     * Creating the cart rated it in this process. A visit from scratch has nothing stored and nothing remembered
     * from an earlier request.
     */
    private function startAsANewVisit(): void
    {
        $rates = self::getContainer()->get('jpmmartin_carrier.cache.rates');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rates);
        $rates->clear();

        $servicesResetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetterInterface::class, $servicesResetter);
        $servicesResetter->reset();
    }
}
