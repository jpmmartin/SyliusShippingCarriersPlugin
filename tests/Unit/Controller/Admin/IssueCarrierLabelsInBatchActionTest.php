<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin\IssueCarrierLabelsInBatchAction;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\BatchIssuerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\BatchResult;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * What the operator is left with after a batch. A batch that only says «done» hides the shipments nobody
 * printed, and those are the ones somebody has to do something about.
 */
final class IssueCarrierLabelsInBatchActionTest extends TestCase
{
    private bool $granted = true;

    private bool $validToken = true;

    /** @var list<BatchResult> */
    private array $results = [];

    /** @var list<int> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->granted = true;
        $this->validToken = true;
        $this->results = [];
        $this->asked = [];
    }

    public function testWithoutTheAdministrationRoleNothingIsIssued(): void
    {
        $this->granted = false;

        $this->expectException(AccessDeniedException::class);

        ($this->action())($this->request([1, 2]));
    }

    public function testWithoutTheAdminsOwnTokenNothingIsIssued(): void
    {
        $this->validToken = false;

        $this->expectException(AccessDeniedException::class);

        ($this->action())($this->request([1, 2]));
    }

    public function testThePickedShipmentsAreTheOnesIssued(): void
    {
        $this->results = [$this->issued(1), $this->issued(2)];

        ($this->action())($this->request([1, 2]));

        self::assertSame([1, 2], $this->asked);
    }

    public function testPickingNothingIssuesNothingAndSaysSo(): void
    {
        $request = $this->request([]);

        ($this->action())($request);

        self::assertSame([], $this->asked);
        $flashes = $this->flashes($request);
        self::assertSame(['error'], array_keys($flashes));
        self::assertStringContainsString('No shipment was picked', $flashes['error'][0] ?? '');
    }

    /**
     * The summary of a batch: the count, and then every shipment that did not go out with its reason.
     */
    public function testTheSummarySaysHowManyWentOutAndWhyEachOneDidNot(): void
    {
        $this->results = [
            $this->issued(1),
            $this->failed(2, 'UPS rejected the shipment request: the postcode is not served.'),
            $this->needsCheck(3, 'UPS could not be reached: the request timed out.'),
        ];
        $request = $this->request([1, 2, 3]);

        ($this->action())($request);

        $flashes = $this->flashes($request);
        self::assertSame(['success', 'error', 'warning'], array_keys($flashes));
        self::assertSame(['1 of 3 shipment(s) issued.'], $flashes['success']);
        self::assertSame(['Shipment 2: UPS rejected the shipment request: the postcode is not served.'], $flashes['error']);
        self::assertSame(['Shipment 3: UPS could not be reached: the request timed out.'], $flashes['warning']);
    }

    /**
     * A shipment that was issued has nothing to explain, so it is not listed again.
     */
    public function testAShipmentThatWentOutIsNotListedAsAProblem(): void
    {
        $this->results = [$this->issued(1), $this->issued(2)];
        $request = $this->request([1, 2]);

        ($this->action())($request);

        $flashes = $this->flashes($request);
        self::assertSame(['success'], array_keys($flashes));
        self::assertSame(['2 of 2 shipment(s) issued.'], $flashes['success']);
    }

    public function testABatchWhereNothingWentOutIsNotAnnouncedAsASuccess(): void
    {
        $this->results = [$this->failed(1, 'No credentials are stored for the carrier "ups".')];
        $request = $this->request([1]);

        ($this->action())($request);

        $flashes = $this->flashes($request);
        self::assertSame(['error'], array_keys($flashes));
        self::assertSame('0 of 1 shipment(s) issued.', $flashes['error'][0] ?? '');
    }

    private function action(): IssueCarrierLabelsInBatchAction
    {
        /** @var RepositoryInterface<ShipmentInterface>&Stub $shipmentRepository */
        $shipmentRepository = $this->createStub(RepositoryInterface::class);
        $shipmentRepository->method('find')->willReturnCallback(fn (mixed $id): ShipmentInterface => $this->shipment(is_numeric($id) ? (int) $id : 0));

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(fn (): bool => $this->granted);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturnCallback(fn (CsrfToken $token): bool => $this->validToken && 'the token' === $token->getValue());

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('warehouse@example.com', null), 'admin'));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/admin/shipments/');

        return new IssueCarrierLabelsInBatchAction(
            $shipmentRepository,
            $this->batchIssuer(),
            $authorizationChecker,
            $csrfTokenManager,
            $tokenStorage,
            $urlGenerator,
        );
    }

    private function batchIssuer(): BatchIssuerInterface
    {
        return new class(function (iterable $shipments): array {
            foreach ($shipments as $shipment) {
                $this->asked[] = (int) $shipment->getId();
            }

            return $this->results;
        }) implements BatchIssuerInterface {
            /**
             * @param \Closure(iterable<ShipmentInterface>): list<BatchResult> $issue
             */
            public function __construct(
                private readonly \Closure $issue,
            ) {
            }

            public function issue(iterable $shipments, string $issuedBy): array
            {
                return ($this->issue)($shipments);
            }
        };
    }

    private function issued(int $id): BatchResult
    {
        return BatchResult::of($this->shipment($id), $this->export(CarrierShipmentExportInterface::STATE_ISSUED, null));
    }

    private function failed(int $id, string $reason): BatchResult
    {
        return BatchResult::of($this->shipment($id), $this->export(CarrierShipmentExportInterface::STATE_FAILED, $reason));
    }

    private function needsCheck(int $id, string $reason): BatchResult
    {
        return BatchResult::of($this->shipment($id), $this->export(CarrierShipmentExportInterface::STATE_NEEDS_CHECK, $reason));
    }

    /**
     * @param CarrierShipmentExportInterface::STATE_* $state
     */
    private function export(string $state, ?string $reason): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState($state);
        $export->setFailureReason($reason);

        return $export;
    }

    /**
     * @param list<int> $ids
     */
    private function request(array $ids): Request
    {
        $request = new Request([], ['_csrf_token' => 'the token', 'ids' => $ids]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @return array<string, list<string>>
     */
    private function flashes(Request $request): array
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        /** @var array<string, list<string>> $flashes */
        $flashes = $session->getFlashBag()->all();

        return $flashes;
    }

    private function shipment(int $id): ShipmentInterface
    {
        $order = new Order();
        $shipment = new Shipment();
        $order->addShipment($shipment);

        (new \ReflectionProperty(Shipment::class, 'id'))->setValue($shipment, $id);

        return $shipment;
    }
}
