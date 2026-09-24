<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin\IssueCarrierLabelsAction;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AlreadyIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AmbiguousShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelIssuerInterface;
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
 * The door to issuing. What it hands out is paid for on the merchant's account, so it only opens for an
 * administrator who asked for it from the admin — and what the carrier answers, however bad, comes back as a
 * page and not as a server error.
 */
final class IssueCarrierLabelsActionTest extends TestCase
{
    private const SHIPMENT_ID = 42;

    private const ORDER_ID = 7;

    private bool $granted = true;

    private bool $validToken = true;

    private CarrierShipmentExportInterface|\Throwable $outcome;

    private ?ShipmentInterface $shipment = null;

    protected function setUp(): void
    {
        $this->granted = true;
        $this->validToken = true;
        $this->shipment = $this->shipment();
        $this->outcome = $this->export(CarrierShipmentExportInterface::STATE_ISSUED);
    }

    /**
     * The one that matters. Everything else is comfort.
     */
    public function testWithoutTheAdministrationRoleNothingIsIssued(): void
    {
        $this->granted = false;

        $this->expectException(AccessDeniedException::class);

        ($this->action())($this->request(), self::SHIPMENT_ID);
    }

    /**
     * A link on another site must not be able to spend the merchant's money.
     */
    public function testWithoutTheAdminsOwnTokenNothingIsIssued(): void
    {
        $this->validToken = false;

        $this->expectException(AccessDeniedException::class);

        ($this->action())($this->request(), self::SHIPMENT_ID);
    }

    public function testAShipmentThatIsNotThereIsNotFound(): void
    {
        $this->shipment = null;

        $this->expectException(NotFoundHttpException::class);

        ($this->action())($this->request(), self::SHIPMENT_ID);
    }

    public function testTheLabelsAreIssuedForWhoeverAskedAndThePageComesBack(): void
    {
        $request = $this->request();

        $response = ($this->action())($request, self::SHIPMENT_ID);

        $flashes = $this->flashes($request);
        self::assertSame('/admin/orders/' . self::ORDER_ID, $response->getTargetUrl());
        self::assertSame(['success'], array_keys($flashes));
        self::assertStringContainsString('1Z999AA10123456784', $flashes['success'][0] ?? '');
    }

    public function testACarrierThatRefusedIsToldAsAnErrorAndNotAsAServerError(): void
    {
        $export = $this->export(CarrierShipmentExportInterface::STATE_FAILED);
        $export->setFailureReason('UPS rejected the shipment request: the postcode is not served.');
        $this->outcome = $export;
        $request = $this->request();

        $response = ($this->action())($request, self::SHIPMENT_ID);

        $flashes = $this->flashes($request);
        self::assertTrue($response->isRedirect());
        self::assertSame(['error'], array_keys($flashes));
        self::assertStringContainsString('the postcode is not served', $flashes['error'][0] ?? '');
    }

    /**
     * Not an error and not a success: the operator is told to look before trying again.
     */
    public function testAShipmentNobodyKnowsTheFateOfIsToldAsAWarning(): void
    {
        $export = $this->export(CarrierShipmentExportInterface::STATE_NEEDS_CHECK);
        $export->setFailureReason('UPS could not be reached: the request timed out.');
        $this->outcome = $export;
        $request = $this->request();

        ($this->action())($request, self::SHIPMENT_ID);

        $flashes = $this->flashes($request);
        self::assertSame(['warning'], array_keys($flashes));
        self::assertStringContainsString('Check it with the carrier', $flashes['warning'][0] ?? '');
    }

    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function refusals(): iterable
    {
        yield 'already issued' => [new AlreadyIssuedException('The shipment already has its labels.'), 'already has its labels'];
        yield 'waiting to be checked' => [new AmbiguousShipmentException('Nobody knows whether ups issued them.'), 'Nobody knows whether'];
        yield 'not a carrier of this plugin' => [new \InvalidArgumentException('The shipment is not sent by a carrier of this plugin.'), 'not sent by a carrier'];
        yield 'a setting that cannot be used' => [new InvalidCarrierSettingException('label_formats.ups is "PDF", which the carrier does not print labels as.'), 'label_formats.ups is "PDF"'];
    }

    /**
     * A shipment in no state to be issued is an answer for the operator, never a broken admin.
     *
     * @dataProvider refusals
     */
    public function testAShipmentInNoStateToBeIssuedComesBackAsAPage(\Throwable $refusal, string $expected): void
    {
        $this->outcome = $refusal;
        $request = $this->request();

        $response = ($this->action())($request, self::SHIPMENT_ID);

        $flashes = $this->flashes($request);
        self::assertTrue($response->isRedirect());
        self::assertSame(['error'], array_keys($flashes));
        self::assertStringContainsString($expected, $flashes['error'][0] ?? '');
    }

    private function action(): IssueCarrierLabelsAction
    {
        /** @var RepositoryInterface<ShipmentInterface>&Stub $shipmentRepository */
        $shipmentRepository = $this->createStub(RepositoryInterface::class);
        $shipmentRepository->method('find')->willReturnCallback(fn (): ?ShipmentInterface => $this->shipment);

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

        return new IssueCarrierLabelsAction(
            $shipmentRepository,
            $this->labelIssuer(),
            $authorizationChecker,
            $csrfTokenManager,
            $tokenStorage,
            $urlGenerator,
        );
    }

    private function labelIssuer(): LabelIssuerInterface
    {
        return new class($this->outcome) implements LabelIssuerInterface {
            public function __construct(
                private readonly CarrierShipmentExportInterface|\Throwable $outcome,
            ) {
            }

            public function issue(ShipmentInterface $shipment, string $issuedBy): CarrierShipmentExportInterface
            {
                if ($this->outcome instanceof \Throwable) {
                    throw $this->outcome;
                }

                $this->outcome->setIssuedBy($issuedBy);

                return $this->outcome;
            }

            public function confirmNotIssued(CarrierShipmentExportInterface $export, string $confirmedBy): void
            {
                throw new \LogicException('Nothing is confirmed while labels are issued.');
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

    /**
     * @param CarrierShipmentExportInterface::STATE_* $state
     */
    private function export(string $state): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState($state);

        if (CarrierShipmentExportInterface::STATE_ISSUED === $state) {
            $export->setCarrierReference('1Z999AA10123456784');
            $export->addLabel(new CarrierShipmentLabel());
        }

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
