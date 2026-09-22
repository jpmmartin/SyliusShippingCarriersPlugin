<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin\DownloadCarrierDocumentAction;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The controller asks for the administration role itself. The application that hosts the plugin usually
 * protects `/admin` too, which is exactly why this is tested here: a functional test through the admin
 * firewall passes whether or not the controller checks anything.
 */
final class DownloadCarrierDocumentActionTest extends TestCase
{
    private const FILE = 'labels/1Z999.pdf';

    /** @var FilesystemOperator&MockObject */
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(FilesystemOperator::class);
    }

    public function testWithoutTheAdministrationRoleNothingIsEvenRead(): void
    {
        $this->storage->expects(self::never())->method('fileExists');
        $this->storage->expects(self::never())->method('read');

        $this->expectException(AccessDeniedException::class);

        ($this->action(granted: false))(self::FILE);
    }

    public function testWithTheAdministrationRoleTheDocumentIsServedAsAnAttachment(): void
    {
        $this->storage->method('fileExists')->with(self::FILE)->willReturn(true);
        $this->storage->method('mimeType')->with(self::FILE)->willReturn('application/pdf');
        $this->storage->method('read')->with(self::FILE)->willReturn('%PDF-1.4 a label');

        $response = ($this->action(granted: true))(self::FILE);

        self::assertSame('%PDF-1.4 a label', $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('1Z999.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * A document of somebody else's is one shared cache away otherwise.
     */
    public function testTheDocumentIsNeverStoredByACache(): void
    {
        $this->storage->method('fileExists')->willReturn(true);
        $this->storage->method('mimeType')->willReturn('application/pdf');
        $this->storage->method('read')->willReturn('%PDF-1.4 a label');

        $cacheControl = (string) ($this->action(granted: true))(self::FILE)->headers->get('Cache-Control');

        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    /**
     * The path comes from a row now rather than from a URL, but a row can be written by something other than
     * this plugin, and a storage that is not a local directory may happily follow it.
     */
    public function testAPathThatClimbsOutOfTheStorageIsNeverRead(): void
    {
        $this->storage->expects(self::never())->method('fileExists');

        $this->expectException(NotFoundHttpException::class);

        ($this->action(granted: true))('../../../.env');
    }

    public function testADocumentThatIsNotThereIsNotFound(): void
    {
        $this->storage->method('fileExists')->willReturn(false);
        $this->storage->expects(self::never())->method('read');

        $this->expectException(NotFoundHttpException::class);

        ($this->action(granted: true))(self::FILE);
    }

    private function action(bool $granted): DownloadCarrierDocumentAction
    {
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($granted);

        return new DownloadCarrierDocumentAction($this->storage, $authorizationChecker);
    }
}
