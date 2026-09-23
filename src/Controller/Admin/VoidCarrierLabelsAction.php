<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\NotIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelVoiderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Cancels the labels of a shipment with its carrier, because an administrator asked for it.
 *
 * What the carrier answers is what is shown: a carrier that refuses leaves the labels issued, and saying so is
 * the point — a warehouse told a parcel was cancelled stops looking for it.
 *
 * @internal
 */
final readonly class VoidCarrierLabelsAction
{
    private const ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    public const CSRF_TOKEN_ID = 'jpmmartin_carrier_void_labels';

    /**
     * @param RepositoryInterface<ShipmentInterface> $shipmentRepository
     * @param RepositoryInterface<CarrierShipmentExportInterface> $exportRepository
     */
    public function __construct(
        private RepositoryInterface $shipmentRepository,
        private RepositoryInterface $exportRepository,
        private LabelVoiderInterface $labelVoider,
        private AuthorizationCheckerInterface $authorizationChecker,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, int $id): RedirectResponse
    {
        if (!$this->authorizationChecker->isGranted(self::ROLE)) {
            throw new AccessDeniedException('Only an administrator cancels the labels of a shipment.');
        }

        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedException('The request to cancel the labels did not come from the admin.');
        }

        $shipment = $this->shipmentRepository->find($id);
        if (!$shipment instanceof ShipmentInterface) {
            throw new NotFoundHttpException(sprintf('There is no shipment %d.', $id));
        }

        $export = $this->exportRepository->findOneBy(['shipment' => $shipment]);
        if (!$export instanceof CarrierShipmentExportInterface) {
            throw new NotFoundHttpException(sprintf('The shipment %d was never handed to a carrier.', $id));
        }

        $flashes = $this->flashes($request);

        try {
            $result = $this->labelVoider->void($export, $this->who());
        } catch (NotIssuedException | CarrierException $exception) {
            $flashes?->add('error', $exception->getMessage());

            return $this->back($shipment);
        }

        $flashes?->add(
            $result->voided ? 'success' : 'error',
            $result->voided
                ? sprintf('%s cancelled the shipment %s.', (string) $export->getCarrier(), (string) $export->getCarrierReference())
                : sprintf('The labels are still issued: %s', (string) $result->reason),
        );

        return $this->back($shipment);
    }

    /**
     * Whoever asked, named as they are named now. The record keeps the name, not a link to a user.
     */
    private function who(): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return null === $user ? 'unknown' : $user->getUserIdentifier();
    }

    private function back(ShipmentInterface $shipment): RedirectResponse
    {
        $orderId = $shipment->getOrder()?->getId();
        if (null === $orderId) {
            throw new NotFoundHttpException('The shipment does not belong to an order.');
        }

        return new RedirectResponse($this->urlGenerator->generate('sylius_admin_order_show', ['id' => $orderId]));
    }

    /**
     * Null when the request carries no session, which a command-line one does not.
     */
    private function flashes(Request $request): ?FlashBagInterface
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        return $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : null;
    }
}
