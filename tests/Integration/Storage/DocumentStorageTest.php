<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Storage;

use JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\JpmMartinSyliusShippingCarriersExtension;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A label is a document that lets whoever holds it send a parcel on the merchant's account, and a customs
 * document carries what was in the parcel and what it was worth. Neither may be one guessed URL away.
 *
 * The `documents_elsewhere` environment is configured in tests/TestApplication/config/config.yaml.
 */
final class DocumentStorageTest extends KernelTestCase
{
    private const FILE = 'test/label.pdf';

    protected function tearDown(): void
    {
        if (null !== static::$kernel) {
            $projectDir = self::getContainer()->getParameter('kernel.project_dir');
            self::assertIsString($projectDir);
            foreach (['var/jpmmartin_carrier/documents', 'var/somewhere-else'] as $directory) {
                $written = rtrim($projectDir, '/') . '/' . $directory . '/' . self::FILE;
                if (is_file($written)) {
                    unlink($written);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * The one that matters: what the plugin writes must not land anywhere the web server publishes.
     */
    public function testWhatIsWrittenDoesNotLandUnderThePublishedDirectory(): void
    {
        self::bootKernel();

        $this->storage()->write(self::FILE, 'a label');

        $written = $this->documentsDirectory() . '/' . self::FILE;
        self::assertFileExists($written);
        self::assertStringStartsNotWith($this->publishedDirectory(), (string) realpath($written));
    }

    public function testTheDirectoryItselfIsOutsideThePublishedDirectory(): void
    {
        self::bootKernel();

        self::assertStringStartsNotWith($this->publishedDirectory(), $this->documentsDirectory());
    }

    /**
     * Not only out of the published directory: private, so a filesystem that serves what it is told to serve
     * is not told to serve these.
     */
    public function testWhatIsWrittenIsPrivate(): void
    {
        self::bootKernel();

        $this->storage()->write(self::FILE, 'a label');

        self::assertSame(Visibility::PRIVATE, $this->storage()->visibility(self::FILE));
    }

    /**
     * An application keeps the documents elsewhere from its own configuration, without touching the plugin.
     */
    public function testAnApplicationPointsTheStorageElsewhere(): void
    {
        self::bootKernel(['environment' => 'documents_elsewhere']);

        $this->storage()->write(self::FILE, 'a label');

        // Where the file actually lands, which is the only thing that says the storage was re-pointed.
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        self::assertFileExists(rtrim($projectDir, '/') . '/var/somewhere-else/' . self::FILE);
        self::assertFileDoesNotExist($this->documentsDirectory() . '/' . self::FILE);
    }

    /**
     * The setting the README tells an integrator about. The kernel above re-points the whole storage, which is
     * the other way in; this one changes nothing but `documents_dir`, so if the plugin stopped reading it
     * nothing else would notice.
     */
    public function testTheSettingOfThePluginMovesTheDocuments(): void
    {
        self::bootKernel(['environment' => 'documents_dir_configured']);

        $this->storage()->write(self::FILE, 'a label');

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $expected = rtrim($projectDir, '/') . '/var/documents-by-setting';
        self::assertSame($expected, $this->documentsDirectory());
        self::assertFileExists($expected . '/' . self::FILE);
    }

    private function storage(): FilesystemOperator
    {
        $storage = self::getContainer()->get(JpmMartinSyliusShippingCarriersExtension::DOCUMENT_STORAGE);
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }

    private function documentsDirectory(): string
    {
        $directory = self::getContainer()->getParameter('jpmmartin_carrier.documents_dir');
        self::assertIsString($directory);

        return rtrim($directory, '/');
    }

    /**
     * What the web server publishes, read from the application that hosts the plugin.
     */
    /**
     * @return non-empty-string
     */
    private function publishedDirectory(): string
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $published = rtrim($projectDir, '/') . '/public';
        self::assertNotSame('', $published);

        return $published;
    }
}
