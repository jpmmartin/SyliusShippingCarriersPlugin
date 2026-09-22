<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
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
     * Credentials the store cannot decrypt — the key changed, is missing, or the database came from another
     * installation. The form opens without showing the ciphertext, says why the fields are empty, and does not
     * let a save that retypes nothing pass for a repair.
     */
    public function testUnreadableCredentialsHaveToBeTypedAgain(): void
    {
        $this->submitCreateForm([
            self::FORM . '[carrier]' => 'ups',
            self::FORM . '[environment]' => 'sandbox',
            self::FORM . '[pickupType]' => 'drop_off',
            self::FORM . '[credentials][client_id]' => 'client-id-1',
            self::FORM . '[credentials][client_secret]' => 'secret-1',
            self::FORM . '[credentials][account_number]' => 'A1B2C3',
        ]);
        self::assertResponseRedirects();
        $id = $this->findUpsCredentials()->getId();

        $otherKeyPath = sys_get_temp_dir() . '/jpmmartin_carrier_other_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $otherKeyPath);
        $otherEncrypter = new Encrypter($otherKeyPath);
        $unreadable = json_encode([
            'client_id' => $otherEncrypter->encrypt('client-id-1'),
            'client_secret' => $otherEncrypter->encrypt('secret-1'),
            'account_number' => $otherEncrypter->encrypt('A1B2C3'),
        ], \JSON_THROW_ON_ERROR);
        unlink($otherKeyPath);
        $this->entityManager->getConnection()->update('jpmmartin_carrier_credentials', ['credentials' => $unreadable], ['id' => $id]);
        $this->entityManager->clear();

        $crawler = $this->client->request('GET', sprintf('/admin/carrier-credentials/%d/edit', $id));
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(EncrypterInterface::ENCRYPTION_SUFFIX, $content, 'No ciphertext is drawn in the form.');
        self::assertStringContainsString('could not be read', $content, 'The form says why the fields are empty.');

        // Saving without typing anything again is refused, and nothing changes.
        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form());
        self::assertFalse($this->client->getResponse()->isRedirect(), 'A save that retypes nothing does not pass for a repair.');
        $this->assertRefusedAsUnreadable();
        self::assertSame($unreadable, $this->storedCredentialsColumn($id));

        // Nor does leaving the account number alone: optional for UPS, but left empty it would be lost without
        // a word, and with it the negotiated rates and the labels.
        $crawler = $this->client->request('GET', sprintf('/admin/carrier-credentials/%d/edit', $id));
        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[credentials][client_id]' => 'client-id-2',
            self::FORM . '[credentials][client_secret]' => 'secret-2',
        ]));
        self::assertFalse($this->client->getResponse()->isRedirect(), 'An unreadable account number is not dropped by leaving it empty.');
        $this->assertRefusedAsUnreadable();
        self::assertSame($unreadable, $this->storedCredentialsColumn($id));

        // Nor does retyping the client id alone: an empty secret keeps the stored one only when it can be read.
        $crawler = $this->client->request('GET', sprintf('/admin/carrier-credentials/%d/edit', $id));
        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[credentials][client_id]' => 'client-id-2',
        ]));
        self::assertFalse($this->client->getResponse()->isRedirect(), 'An unreadable secret is not kept by leaving it empty.');
        $this->assertRefusedAsUnreadable();
        self::assertSame($unreadable, $this->storedCredentialsColumn($id));

        // Typed again, they are stored with this store's key and can be used.
        $crawler = $this->client->request('GET', sprintf('/admin/carrier-credentials/%d/edit', $id));
        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[credentials][client_id]' => 'client-id-2',
            self::FORM . '[credentials][client_secret]' => 'secret-2',
            self::FORM . '[credentials][account_number]' => 'A1B2C3',
        ]));
        self::assertResponseRedirects();

        $credentialsProvider = self::getContainer()->get('jpmmartin_carrier.carrier.credentials_provider');
        self::assertInstanceOf(CredentialsProvider::class, $credentialsProvider);
        $this->entityManager->clear();
        self::assertSame('client-id-2', $credentialsProvider->get('ups')->getCredentials()['client_id'] ?? null);
    }

    /**
     * The form comes back saying why, and still without a single character of ciphertext in it.
     */
    private function assertRefusedAsUnreadable(): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('could not be read with this store', $content);
        self::assertStringNotContainsString(EncrypterInterface::ENCRYPTION_SUFFIX, $content, 'No ciphertext is drawn when the form comes back.');
    }

    private function storedCredentialsColumn(mixed $id): string
    {
        $column = $this->entityManager->getConnection()->fetchOne('SELECT credentials FROM jpmmartin_carrier_credentials WHERE id = ?', [$id]);
        self::assertIsString($column);

        return $column;
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
     * Who pays the duties and taxes of an international shipment: the recipient unless the administrator says
     * the store does, and an edit that does not touch the choice keeps it.
     */
    public function testTheAdministratorChoosesWhoPaysDutiesAndTaxes(): void
    {
        $this->submitCreateForm([
            self::FORM . '[carrier]' => 'ups',
            self::FORM . '[environment]' => 'sandbox',
            self::FORM . '[pickupType]' => 'drop_off',
            self::FORM . '[credentials][client_id]' => 'client-id-1',
            self::FORM . '[credentials][client_secret]' => 'secret-1',
        ]);
        self::assertResponseRedirects();
        self::assertSame('recipient', $this->findUpsCredentials()->getDutiesPayer());

        $id = $this->findUpsCredentials()->getId();
        $crawler = $this->client->request('GET', sprintf('/admin/carrier-credentials/%d/edit', $id));
        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[dutiesPayer]' => 'shipper',
        ]));
        self::assertResponseRedirects();
        self::assertSame('shipper', $this->findUpsCredentials()->getDutiesPayer());

        $crawler = $this->client->request('GET', sprintf('/admin/carrier-credentials/%d/edit', $id));
        self::assertSame(
            'shipper',
            $crawler->filter(sprintf('select[name="%s[dutiesPayer]"] option[selected]', self::FORM))->attr('value'),
            'The edit form has to show the choice that is stored.',
        );
        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[credentials][client_id]' => 'client-id-2',
        ]));
        self::assertResponseRedirects();
        self::assertSame('shipper', $this->findUpsCredentials()->getDutiesPayer());
    }

    /**
     * Credentials saved before the choice existed have no value of their own for it, and must keep issuing
     * labels without anyone opening them: the database gives them the recipient.
     */
    public function testCredentialsSavedBeforeTheChoiceExistedAreLeftWithTheRecipient(): void
    {
        $this->entityManager->getConnection()->insert('jpmmartin_carrier_credentials', [
            'carrier' => 'ups',
            'environment' => 'sandbox',
            'pickup_type' => 'drop_off',
            'credentials' => '{}',
        ]);

        self::assertSame('recipient', $this->findUpsCredentials()->getDutiesPayer());
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
