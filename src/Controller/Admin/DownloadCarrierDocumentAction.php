<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Serves a document of the plugin — a label, a customs document — to an authorised administrator.
 *
 * The storage is private and outside the published directory, so this is the only way in, and it asks for the
 * administration role itself instead of trusting the application's access rules: what it hands over lets
 * whoever holds it send a parcel on the merchant's account.
 */
final readonly class DownloadCarrierDocumentAction
{
    private const ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    public function __construct(
        private FilesystemOperator $storage,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function __invoke(string $path): Response
    {
        if (!$this->authorizationChecker->isGranted(self::ROLE)) {
            throw new AccessDeniedException('Only an administrator downloads the documents of a carrier.');
        }

        // Belt and braces: the local adapter refuses a path that leaves its root, but a storage of another
        // kind may not, and this one is built from the URL.
        if (str_contains($path, '..')) {
            throw new NotFoundHttpException(sprintf('There is no document "%s".', $path));
        }

        try {
            if (!$this->storage->fileExists($path)) {
                throw new NotFoundHttpException(sprintf('There is no document "%s".', $path));
            }

            $mimeType = $this->storage->mimeType($path);
            // Read whole rather than streamed: a label or a customs document is a few hundred kilobytes, and a
            // response that holds its bytes survives being handed around, which a stream does not.
            $contents = $this->storage->read($path);
        } catch (FilesystemException $exception) {
            throw new NotFoundHttpException(sprintf('There is no document "%s".', $path), $exception);
        }

        $response = new Response($contents);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path)),
        );
        // Never in a shared cache: it is one request away from being served to somebody else.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
