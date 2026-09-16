<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierHttpClientFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsAccessTokenCache;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsClientFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ShipStream\Ups\Authentication\AccessToken;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * D-25: the UPS access token is shared by every process and renewed by one of them at a time. Each process
 * is simulated by its own client factory, sharing only what processes share: the cache pool and the lock
 * store.
 */
final class UpsAccessTokenTest extends TestCase
{
    private const CLIENT_ID = 'ups-client-id';

    /** @var list<string|null> The Authorization header of every rate request */
    private array $rateAuthorizations = [];

    private int $tokenRequests = 0;

    private ArrayAdapter $pool;

    private InMemoryStore $lockStore;

    /** @var list<string> */
    private array $keyPaths = [];

    private Encrypter $encrypter;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->lockStore = new InMemoryStore();
        $this->encrypter = new Encrypter($this->createKey());
    }

    protected function tearDown(): void
    {
        foreach ($this->keyPaths as $keyPath) {
            if (is_file($keyPath)) {
                unlink($keyPath);
            }
        }
    }

    public function testASecondProcessReusesTheTokenWithoutRequestingAnother(): void
    {
        $this->process()->rate($this->request());
        $this->process()->rate($this->request());

        self::assertSame(1, $this->tokenRequests);
        self::assertSame(['Authorization: Bearer ups-access-token', 'Authorization: Bearer ups-access-token'], $this->rateAuthorizations);
    }

    public function testAnExpiredTokenIsRenewedOnceWhenTwoProcessesNeedItAtTheSameTime(): void
    {
        $this->storeToken('expired-token', issuedAt: time() - 20000);
        $first = $this->process();

        // The second process asks for the lock while the first one holds it, so it waits for the first one to
        // renew the token before it gets the lock.
        $second = $this->process(new WaitingStore($this->lockStore, fn () => $first->rate($this->request())));
        $second->rate($this->request());

        self::assertSame(1, $this->tokenRequests);
        self::assertSame(['Authorization: Bearer ups-access-token', 'Authorization: Bearer ups-access-token'], $this->rateAuthorizations);
    }

    public function testTheTokenIsNotStoredInClear(): void
    {
        $this->process()->rate($this->request());

        $stored = $this->pool->getItem('ups_access_token_' . hash('sha256', self::CLIENT_ID))->get();
        self::assertIsString($stored);
        self::assertStringNotContainsString('ups-access-token', $stored);
    }

    public function testATokenThatCannotBeDecryptedIsRequestedAgain(): void
    {
        $this->storeToken('token-from-another-key', issuedAt: time(), encrypter: new Encrypter($this->createKey()));

        $this->process()->rate($this->request());

        self::assertSame(1, $this->tokenRequests);
        self::assertSame(['Authorization: Bearer ups-access-token'], $this->rateAuthorizations);
    }

    private function process(?PersistingStoreInterface $lockStore = null): UpsCarrier
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, '/security/v1/oauth/token')) {
                ++$this->tokenRequests;

                return $this->json('token.json', ['%issued_at%' => (string) (time() * 1000)]);
            }

            /** @var array{authorization?: list<string>} $headers */
            $headers = $options['normalized_headers'] ?? [];
            $this->rateAuthorizations[] = $headers['authorization'][0] ?? null;

            return $this->json('rate-shop.json');
        });

        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => self::CLIENT_ID,
            CarrierCredentialsInterface::CLIENT_SECRET => 'ups-client-secret',
        ]);

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($credentials);

        return new UpsCarrier(
            new CredentialsProvider($repository),
            new UpsClientFactory(
                CarrierHttpClientFactory::create($httpClient, 10.0),
                new UpsAccessTokenCache($this->pool, $this->encrypter),
                new LockFactory($lockStore ?? $this->lockStore),
            ),
        );
    }

    private function storeToken(string $token, int $issuedAt, ?Encrypter $encrypter = null): void
    {
        (new UpsAccessTokenCache($this->pool, $encrypter ?? $this->encrypter))->save(
            (new AccessToken())->setClientId(self::CLIENT_ID)->setAccessToken($token)->setIssuedAt($issuedAt)->setExpiresIn(14399),
        );
    }

    private function request(): RateRequest
    {
        return new RateRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL'),
            new Address('US', '98101', 'Seattle', '500 Pine St', 'WA'),
            [new Package('Medium', 11.0, 13.0, 9.0, 'in', 5.5, 'lb', [])],
        );
    }

    /**
     * @param array<string, string> $replacements
     */
    private function json(string $fixture, array $replacements = []): MockResponse
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
        self::assertIsString($contents);

        return new MockResponse(strtr($contents, $replacements), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function createKey(): string
    {
        $keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_ups_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $keyPath);
        $this->keyPaths[] = $keyPath;

        return $keyPath;
    }
}
