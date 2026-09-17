<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use ParagonIE\Halite\KeyFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CarrierCredentialsAdminTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const FORM = 'jpmmartin_carrier_credentials';

    private const KEY_PATH_VARIABLE = 'JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH';

    private string $keyPath;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
        $_SERVER[self::KEY_PATH_VARIABLE] = $this->keyPath;
        $_ENV[self::KEY_PATH_VARIABLE] = $this->keyPath;

        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();

        $this->client->loginUser($this->createAdmin('credentials-admin'), 'admin');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();

        unset($_SERVER[self::KEY_PATH_VARIABLE], $_ENV[self::KEY_PATH_VARIABLE]);

        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    public function testTheAdministratorCreatesEditsAndDeletesCredentials(): void
    {
        $this->submitCreateForm([
            self::FORM . '[carrier]' => 'ups',
            self::FORM . '[environment]' => 'sandbox',
            self::FORM . '[pickupType]' => 'drop_off',
            self::FORM . '[credentials][client_id]' => 'client-id-1',
            self::FORM . '[credentials][client_secret]' => 'secret-1',
        ]);
        self::assertResponseRedirects();

        $credentials = $this->findUpsCredentials();
        self::assertSame('sandbox', $credentials->getEnvironment());
        self::assertSame('drop_off', $credentials->getPickupType());
        self::assertSame('secret-1', $credentials->getCredentials()['client_secret']);
        $id = $credentials->getId();

        // The edit form never shows the stored secret.
        $crawler = $this->client->request('GET', sprintf('/admin/carrier-credentials/%d/edit', $id));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('secret-1', (string) $this->client->getResponse()->getContent());

        // Leaving the secret empty keeps the stored one.
        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[credentials][client_id]' => 'client-id-2',
        ]));
        self::assertResponseRedirects();

        $credentials = $this->findUpsCredentials();
        self::assertSame('client-id-2', $credentials->getCredentials()['client_id']);
        self::assertSame('secret-1', $credentials->getCredentials()['client_secret']);

        // Writing a new one replaces it.
        $crawler = $this->client->request('GET', sprintf('/admin/carrier-credentials/%d/edit', $id));
        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[credentials][client_secret]' => 'secret-2',
        ]));
        self::assertResponseRedirects();

        self::assertSame('secret-2', $this->findUpsCredentials()->getCredentials()['client_secret']);

        // Deleted through the confirmation form the index renders, which carries the CSRF token.
        $crawler = $this->client->request('GET', '/admin/carrier-credentials/');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[action$="/admin/carrier-credentials/%d"]', $id))->form());
        self::assertResponseRedirects();

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(CarrierCredentials::class, $id));
    }

    /**
     * The environment is an explicit choice, so the form refuses credentials without one.
     */
    public function testCredentialsWithoutAnEnvironmentAreRejected(): void
    {
        $this->submitCreateForm([
            self::FORM . '[carrier]' => 'ups',
            self::FORM . '[environment]' => '',
            self::FORM . '[pickupType]' => 'drop_off',
            self::FORM . '[credentials][client_id]' => 'client-id-1',
            self::FORM . '[credentials][client_secret]' => 'secret-1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choose whether these credentials go against the sandbox or production.');
    }

    /**
     * How packages reach the carrier changes the rates, so there is no default either.
     */
    public function testCredentialsWithoutAPickupTypeAreRejected(): void
    {
        $this->submitCreateForm([
            self::FORM . '[carrier]' => 'ups',
            self::FORM . '[environment]' => 'sandbox',
            self::FORM . '[pickupType]' => '',
            self::FORM . '[credentials][client_id]' => 'client-id-1',
            self::FORM . '[credentials][client_secret]' => 'secret-1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choose how packages reach the carrier, since it changes the rates.');
    }

    /**
     * FedEx does not quote without an account number.
     */
    public function testFedExCredentialsNeedAnAccountNumber(): void
    {
        $this->submitCreateForm([
            self::FORM . '[carrier]' => 'fedex',
            self::FORM . '[environment]' => 'sandbox',
            self::FORM . '[pickupType]' => 'drop_off',
            self::FORM . '[credentials][client_id]' => 'client-id-1',
            self::FORM . '[credentials][client_secret]' => 'secret-1',
            self::FORM . '[credentials][account_number]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'FedEx needs an account number to quote rates.');
    }

    /** @param array<string, string> $values */
    private function submitCreateForm(array $values): void
    {
        $crawler = $this->client->request('GET', '/admin/carrier-credentials/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form($values));
    }

    private function findUpsCredentials(): CarrierCredentials
    {
        $this->entityManager->clear();

        $credentials = $this->entityManager->getRepository(CarrierCredentials::class)->findOneBy(['carrier' => 'ups']);
        self::assertInstanceOf(CarrierCredentials::class, $credentials);

        return $credentials;
    }
}
