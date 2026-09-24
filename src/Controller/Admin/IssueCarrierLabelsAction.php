<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AlreadyIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AmbiguousShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelIssuerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
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
 * Issues the labels of one shipment, because an administrator asked for it and for no other reason: a label
 * costs money and goes out on the merchant's account, so it is never a side effect of something else.
 *
 * A carrier that refuses or that says nothing is not an error of the admin: it is recorded on the shipment and
 * told to whoever asked, and the page comes back as a page.
 *
 * @internal
 */
final readonly class IssueCarrierLabelsAction
{
    private const ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    public const CSRF_TOKEN_ID = 'jpmmartin_carrier_issue_labels';

    /**
     * @param RepositoryInterface<ShipmentInterface> $shipmentRepository
     */
    public function __construct(
        private RepositoryInterface $shipmentRepository,
        private LabelIssuerInterface $labelIssuer,
        private AuthorizationCheckerInterface $authorizationChecker,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, int $id): RedirectResponse
    {
        if (!$this->authorizationChecker->isGranted(self::ROLE)) {
            throw new AccessDeniedException('Only an administrator issues the labels of a shipment.');
        }

        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedException('The request to issue the labels did not come from the admin.');
        }

        $shipment = $this->shipmentRepository->find($id);
        if (!$shipment instanceof ShipmentInterface) {
            throw new NotFoundHttpException(sprintf('There is no shipment %d.', $id));
        }

        $flashes = $this->flashes($request);

        try {
            $export = $this->labelIssuer->issue($shipment, $this->who());
        } catch (AlreadyIssuedException | AmbiguousShipmentException | InvalidCarrierSettingException | \InvalidArgumentException $exception) {
            // The shipment is in no state to be issued. Saying so is an answer, not a failure of the admin.
            $flashes?->add('error', $exception->getMessage());

            return $this->back($shipment);
        }

        $flashes?->add(...$this->outcome($export));

        return $this->back($shipment);
    }

    /**
     * @return array{string, string}
     */
    private function outcome(CarrierShipmentExportInterface $export): array
    {
        return match ($export->getState()) {
            CarrierShipmentExportInterface::STATE_ISSUED => ['success', sprintf(
                '%d label(s) issued by %s. The shipment travels as %s.',
                \count($export->getLabels()),
                (string) $export->getCarrier(),
                (string) $export->getCarrierReference(),
            )],
            CarrierShipmentExportInterface::STATE_NEEDS_CHECK => ['warning', sprintf(
                'Nobody knows whether %s issued the labels: %s Check it with the carrier before trying again.',
                (string) $export->getCarrier(),
                (string) $export->getFailureReason(),
            )],
            default => ['error', sprintf(
                'The labels were not issued: %s',
                (string) $export->getFailureReason(),
            )],
        };
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
