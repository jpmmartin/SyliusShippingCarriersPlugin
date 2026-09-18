<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\JpmMartinSyliusShippingCarriersExtension;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A label lets whoever holds it send a parcel on the merchant's account. The storage is private and outside
 * the published directory, so this controller is the only way in, and it is the door that has to hold.
 */
final class DownloadCarrierDocumentTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const FILE = 'labels/1Z999.pdf';

    private const CONTENTS = '%PDF-1.4 a label';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();

        $this->storage()->write(self::FILE, self::CONTENTS);
    }

    protected function tearDown(): void
    {
        if ($this->storage()->fileExists(self::FILE)) {
            $this->storage()->delete(self::FILE);
        }

        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * The one that matters. Everything else is comfort.
     */
    public function testWithoutAnAdminSessionTheDocumentIsNotServed(): void
    {
        $this->client->request('GET', '/admin/carrier-documents/' . self::FILE);

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertStringNotContainsString(self::CONTENTS, (string) $this->client->getResponse()->getContent());
    }

    public function testAnAuthorisedAdministratorGetsTheDocument(): void
    {
        $this->client->loginUser($this->createAdmin('document-admin'), 'admin');

        $this->client->request('GET', '/admin/carrier-documents/' . self::FILE);

        self::assertResponseIsSuccessful();
        self::assertSame(self::CONTENTS, $this->client->getResponse()->getContent());
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('1Z999.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    /**
     * The path comes from the URL, so it is the obvious place to try to read something else.
     */
    public function testAPathThatClimbsOutOfTheStorageIsNotServed(): void
    {
        $this->client->loginUser($this->createAdmin('traversal-admin'), 'admin');

        $this->client->request('GET', '/admin/carrier-documents/../../../.env');

        self::assertFalse($this->client->getResponse()->isSuccessful());
    }

    public function testADocumentThatIsNotThereIsNotFound(): void
    {
        $this->client->loginUser($this->createAdmin('missing-document-admin'), 'admin');

        $this->client->request('GET', '/admin/carrier-documents/labels/nothing-here.pdf');

        self::assertResponseStatusCodeSame(404);
    }

    private function storage(): FilesystemOperator
    {
        $storage = self::getContainer()->get(JpmMartinSyliusShippingCarriersExtension::DOCUMENT_STORAGE);
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }
}
