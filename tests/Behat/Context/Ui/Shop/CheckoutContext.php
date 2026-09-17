<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Behat\Mink\Session;
use Behat\Step\Then;
use Behat\Step\When;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;
use Webmozart\Assert\Assert;

final class CheckoutContext implements Context
{
    /** @var array<string, int> The status code of every page and form sent, by what it was */
    private array $statusCodes = [];

    /**
     * @param \ArrayAccess<string, mixed> $minkParameters
     */
    public function __construct(
        private readonly Session $session,
        private readonly \ArrayAccess $minkParameters,
        private readonly UrlGeneratorInterface $router,
        private readonly FakeCarrierState $fakeCarrierState,
    ) {
    }

    /**
     * Reads the cart and every checkout step, and sends the shipping and the confirmation forms when the page has
     * them, the way a buyer would try to get through.
     */
    #[When('I go through my cart and every checkout step')]
    public function iGoThroughMyCartAndEveryCheckoutStep(): void
    {
        $this->statusCodes = [];

        $this->visit('sylius_shop_cart_summary');
        $this->visit('sylius_shop_checkout_address');
        $this->visit('sylius_shop_checkout_select_shipping');
        $this->submit('sylius_shop_checkout_select_shipping');
        $this->visit('sylius_shop_checkout_select_payment');
        $this->visit('sylius_shop_checkout_complete');
        $this->submit('sylius_checkout_complete');
    }

    #[Then('none of them should have failed with a server error')]
    public function noneOfThemShouldHaveFailedWithAServerError(): void
    {
        Assert::notEmpty($this->statusCodes);

        foreach ($this->statusCodes as $what => $statusCode) {
            Assert::lessThan($statusCode, 500, sprintf('%s answered with the server error %d.', $what, $statusCode));
        }
    }

    /**
     * Without it, a failure the scenario set up could go unnoticed behind a rate kept from before.
     */
    #[Then('/^(UPS|FedEx) should have been asked for rates$/')]
    public function theCarrierShouldHaveBeenAskedForRates(string $carrierName): void
    {
        Assert::greaterThan($this->fakeCarrierState->calls(strtolower($carrierName)), 0, sprintf('%s was never asked for rates.', $carrierName));
    }

    private function visit(string $route): void
    {
        $baseUrl = $this->minkParameters['base_url'];
        Assert::string($baseUrl);

        $this->session->visit(rtrim($baseUrl, '/') . $this->router->generate($route, ['_locale' => 'en_US']));
        $this->statusCodes[$route] = $this->session->getStatusCode();
    }

    private function submit(string $formName): void
    {
        $form = $this->session->getPage()->find('css', sprintf('form[name="%s"]', $formName));
        if (null === $form) {
            return;
        }

        $form->submit();
        $this->statusCodes[$formName . ' form'] = $this->session->getStatusCode();
    }
}
