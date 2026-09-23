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
 * Hands a document of the plugin — a label, a customs document — to an authorised administrator.
 *
 * It has no route of its own. Reaching a document by its path was once possible and is not any more: that way
 * in served any file in the store to anyone with the role, while what may be downloaded depends on what
 * happened to the shipment. Every request now comes through the actions that ask that question first, by the
 * record's own id, and this is what they hand the work to once they are satisfied.
 *
 * It still asks for the administration role itself rather than trusting the application's access rules: what
 * it hands over lets whoever holds it send a parcel on the merchant's account, so the check belongs where the
 * bytes are read, not only where the route is declared.
 *
 * @internal
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

        // Belt and braces: the paths come from the plugin's own rows now, and the local adapter refuses one
        // that leaves its root anyway, but a storage of another kind may not and a row can be written by
        // something other than this plugin.
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
