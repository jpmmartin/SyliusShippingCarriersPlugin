<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;
use JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin\VoidCarrierLabelsAction;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\NotIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelVoiderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Cancelling is told to the carrier, so the door to it is the same one as issuing. What the carrier answers is
 * what the operator reads, refusal included.
 */
final class VoidCarrierLabelsActionTest extends TestCase
{
    private const SHIPMENT_ID = 42;

    private const ORDER_ID = 7;

    private bool $granted = true;

    private bool $validToken = true;

    private VoidResult|\Throwable $outcome;

    private ?ShipmentInterface $shipment = null;

    private ?CarrierShipmentExportInterface $export = null;

    protected function setUp(): void
    {
        $this->granted = true;
        $this->validToken = true;
        $this->shipment = $this->shipment();
        $this->export = $this->issuedExport();
        $this->outcome = VoidResult::voided();
    }

    public function testWithoutTheAdministrationRoleNothingIsCancelled(): void
    {
        $this->granted = false;

        $this->expectException(AccessDeniedException::class);

        ($this->action())($this->request(), self::SHIPMENT_ID);
    }

    public function testWithoutTheAdminsOwnTokenNothingIsCancelled(): void
    {
        $this->validToken = false;

        $this->expectException(AccessDeniedException::class);

        ($this->action())($this->request(), self::SHIPMENT_ID);
    }

    public function testAShipmentThatWasNeverHandedToACarrierIsNotFound(): void
    {
        $this->export = null;

        $this->expectException(NotFoundHttpException::class);

        ($this->action())($this->request(), self::SHIPMENT_ID);
    }

    public function testACancelledShipmentIsToldAsASuccessAndThePageComesBack(): void
    {
        $request = $this->request();

        $response = ($this->action())($request, self::SHIPMENT_ID);

        $flashes = $this->flashes($request);
        self::assertSame('/admin/orders/' . self::ORDER_ID, $response->getTargetUrl());
        self::assertSame(['success'], array_keys($flashes));
        self::assertStringContainsString('1Z999AA10123456784', $flashes['success'][0] ?? '');
    }

    /**
     * A refusal is an answer the operator has to read: the parcel is still going out and still being billed.
     */
    public function testACarrierThatRefusesIsToldAsAnErrorAndNotAsAServerError(): void
    {
        $this->outcome = VoidResult::refused('UPS did not void the shipment: it is past the void window.');
        $request = $this->request();

        $response = ($this->action())($request, self::SHIPMENT_ID);

        $flashes = $this->flashes($request);
        self::assertTrue($response->isRedirect());
        self::assertSame(['error'], array_keys($flashes));
        self::assertStringContainsString('still issued', $flashes['error'][0] ?? '');
        self::assertStringContainsString('past the void window', $flashes['error'][0] ?? '');
    }

    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function refusals(): iterable
    {
        yield 'nothing to cancel' => [new NotIssuedException('The shipment has no issued labels to cancel: it is failed.'), 'no issued labels'];
        yield 'carrier out of reach' => [new CarrierUnavailableException('UPS could not be reached: the request timed out.'), 'could not be reached'];
        yield 'a setting that cannot be used' => [new InvalidCarrierSettingException('carrier_timeout is 0, and it cannot be less than 0.1 seconds.'), 'carrier_timeout is 0'];
    }

    /**
     * @dataProvider refusals
     */
    public function testWhatStopsACancellationComesBackAsAPage(\Throwable $refusal, string $expected): void
    {
        $this->outcome = $refusal;
        $request = $this->request();

        $response = ($this->action())($request, self::SHIPMENT_ID);

        $flashes = $this->flashes($request);
        self::assertTrue($response->isRedirect());
        self::assertSame(['error'], array_keys($flashes));
        self::assertStringContainsString($expected, $flashes['error'][0] ?? '');
    }

    private function action(): VoidCarrierLabelsAction
    {
        /** @var RepositoryInterface<ShipmentInterface>&Stub $shipmentRepository */
        $shipmentRepository = $this->createStub(RepositoryInterface::class);
        $shipmentRepository->method('find')->willReturnCallback(fn (): ?ShipmentInterface => $this->shipment);

        /** @var RepositoryInterface<CarrierShipmentExportInterface>&Stub $exportRepository */
        $exportRepository = $this->createStub(RepositoryInterface::class);
        $exportRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierShipmentExportInterface => $this->export);

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(fn (): bool => $this->granted);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturnCallback(fn (CsrfToken $token): bool => $this->validToken && 'the token' === $token->getValue());

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('warehouse@example.com', null), 'admin'));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters): string => '/admin/orders/' . $parameters['id'],
        );

        return new VoidCarrierLabelsAction(
            $shipmentRepository,
            $exportRepository,
            $this->labelVoider(),
            $authorizationChecker,
            $csrfTokenManager,
            $tokenStorage,
            $urlGenerator,
        );
    }

    private function labelVoider(): LabelVoiderInterface
    {
        return new class($this->outcome) implements LabelVoiderInterface {
            public function __construct(
                private readonly VoidResult|\Throwable $outcome,
            ) {
            }

            public function void(CarrierShipmentExportInterface $export, string $voidedBy): VoidResult
            {
                if ($this->outcome instanceof \Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        };
    }

    private function request(): Request
    {
        $request = new Request([], ['_csrf_token' => 'the token']);
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

    private function issuedExport(): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCarrierReference('1Z999AA10123456784');

        return $export;
    }

    private function shipment(): ShipmentInterface
    {
        $order = new Order();
        $shipment = new Shipment();
        $order->addShipment($shipment);

        (new \ReflectionProperty(Order::class, 'id'))->setValue($order, self::ORDER_ID);
        (new \ReflectionProperty(Shipment::class, 'id'))->setValue($shipment, self::SHIPMENT_ID);

        return $shipment;
    }
}
