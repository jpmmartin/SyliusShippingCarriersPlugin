<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Label\BatchIssuerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\BatchResult;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Issues the labels of the shipments an administrator picked in the grid.
 *
 * One shipment failing never stops the others: each one is issued on its own and answers for itself, and when
 * it is over the operator is told how many went out and what happened to every one that did not.
 *
 * @internal
 */
final readonly class IssueCarrierLabelsInBatchAction
{
    private const ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    /**
     * @param RepositoryInterface<ShipmentInterface> $shipmentRepository
     */
    public function __construct(
        private RepositoryInterface $shipmentRepository,
        private BatchIssuerInterface $batchIssuer,
        private AuthorizationCheckerInterface $authorizationChecker,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->authorizationChecker->isGranted(self::ROLE)) {
            throw new AccessDeniedException('Only an administrator issues the labels of a shipment.');
        }

        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(IssueCarrierLabelsAction::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedException('The request to issue the labels did not come from the admin.');
        }

        $flashes = $this->flashes($request);
        $shipments = $this->shipments($request);

        if ([] === $shipments) {
            $flashes?->add('error', 'No shipment was picked, so nothing was issued.');

            return $this->back();
        }

        foreach ($this->summary($this->batchIssuer->issue($shipments, $this->who())) as [$type, $message]) {
            $flashes?->add($type, $message);
        }

        return $this->back();
    }

    /**
     * How many went out, and one line for each one that did not with the reason why. A batch that only says
     * «done» hides the shipments nobody printed, and those are the ones somebody has to do something about.
     *
     * @param list<BatchResult> $results
     *
     * @return list<array{string, string}>
     */
    private function summary(array $results): array
    {
        $issued = array_filter($results, static fn (BatchResult $result): bool => $result->wasIssued());
        $messages = [[
            [] === $issued ? 'error' : 'success',
            sprintf('%d of %d shipment(s) issued.', \count($issued), \count($results)),
        ]];

        foreach ($results as $result) {
            if ($result->wasIssued()) {
                continue;
            }

            // A shipment nobody knows the fate of is not a failure to fix: it is one to go and look at.
            $messages[] = [
                $result->needsCheck() ? 'warning' : 'error',
                sprintf('Shipment %s: %s', (string) $result->shipment->getId(), (string) $result->reason()),
            ];
        }

        return $messages;
    }

    /**
     * The shipments that were picked, in the order they came. An id that is not a shipment is left out: the
     * grid sends what the grid shows, so anything else did not come from it.
     *
     * @return list<ShipmentInterface>
     */
    private function shipments(Request $request): array
    {
        $ids = $request->request->all('ids');

        $shipments = [];
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }

            $shipment = $this->shipmentRepository->find((int) $id);
            if ($shipment instanceof ShipmentInterface) {
                $shipments[] = $shipment;
            }
        }

        return $shipments;
    }

    /**
     * Whoever asked, named as they are named now. The record keeps the name, not a link to a user.
     */
    private function who(): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return null === $user ? 'unknown' : $user->getUserIdentifier();
    }

    private function back(): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('sylius_admin_shipment_index'));
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
