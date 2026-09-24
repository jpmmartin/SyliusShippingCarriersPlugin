<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\DependencyInjection;

use JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\JpmMartinSyliusShippingCarriersExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\RegisterEnvVarProcessorsPass;
use Symfony\Component\DependencyInjection\Compiler\ValidateEnvPlaceholdersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\EnvVarProcessor\DaysEnvVarProcessor;

/**
 * A store deployed the Symfony way gives its settings from environment variables. The plugin's configuration is
 * run through the same compiler passes a kernel runs, in the same order, so what compiles here compiles in a store:
 * `prepend` on the raw configuration, `load` on placeholders, then the check of every placeholder against the type
 * its processor declares.
 */
final class EnvironmentVariablesTest extends TestCase
{
    private const ALIAS = 'jpm_martin_sylius_shipping_carriers';

    public function testEveryNumericSettingAcceptsAnEnvironmentVariableOfItsType(): void
    {
        $container = $this->compile([[
            'carrier_timeout' => '%env(float:CARRIER_TIMEOUT)%',
            'rate_lifetime' => '%env(int:CARRIER_RATE_LIFETIME)%',
            'rate_retention' => '%env(int:CARRIER_RATE_RETENTION)%',
            'tracking_lifetime' => '%env(int:CARRIER_TRACKING_LIFETIME)%',
            'documents_retention' => '%env(int:CARRIER_DOCUMENTS_RETENTION)%',
            'temporary_documents_retention' => '%env(int:CARRIER_TEMPORARY_DOCUMENTS_RETENTION)%',
        ]]);

        self::assertSame('%env(float:CARRIER_TIMEOUT)%', $this->parameter($container, 'carrier_timeout'));
        self::assertSame('%env(int:CARRIER_RATE_LIFETIME)%', $this->parameter($container, 'rate_lifetime'));
        self::assertSame('%env(int:CARRIER_RATE_RETENTION)%', $this->parameter($container, 'rate_retention'));
        self::assertSame('%env(int:CARRIER_TRACKING_LIFETIME)%', $this->parameter($container, 'tracking_lifetime'));
        self::assertSame('%env(int:CARRIER_DOCUMENTS_RETENTION)%', $this->parameter($container, 'documents_retention'));
        self::assertSame('%env(int:CARRIER_TEMPORARY_DOCUMENTS_RETENTION)%', $this->parameter($container, 'temporary_documents_retention'));
    }

    /**
     * The plugin cannot know every processor a store writes. It only has to take the type the processor declares.
     */
    public function testAStoreProcessorThatDeclaresAnIntegerIsAccepted(): void
    {
        $container = $this->compile([['documents_retention' => '%env(days:CARRIER_DOCUMENTS_RETENTION_DAYS)%']]);

        self::assertSame('%env(days:CARRIER_DOCUMENTS_RETENTION_DAYS)%', $this->parameter($container, 'documents_retention'));
    }

    /**
     * Symfony's own check, kept: a variable that can only be a string never reaches a setting that must be a number.
     */
    public function testAVariableWithoutANumericTypeIsRefusedNamingTheSetting(): void
    {
        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage('jpm_martin_sylius_shipping_carriers.documents_retention');

        $this->compile([['documents_retention' => '%env(CARRIER_DOCUMENTS_RETENTION)%']]);
    }

    public function testTheDocumentsDirectoryOfTheApplicationReachesTheStorage(): void
    {
        $this->compile([['documents_dir' => '/srv/carrier-documents']]);

        self::assertSame('/srv/carrier-documents', $this->storageDirectory());
    }

    public function testTheDocumentsDirectoryCanComeFromAnEnvironmentVariable(): void
    {
        $container = $this->compile([['documents_dir' => '%env(CARRIER_DOCUMENTS_DIR)%']]);

        self::assertSame('%env(CARRIER_DOCUMENTS_DIR)%', $container->resolveEnvPlaceholders($this->storageDirectory(), '%%env(%s)%%'));
    }

    /**
     * Configuration files are merged in the order they are loaded, so the last one to set the directory decides it.
     */
    public function testTheLastConfigurationThatSetsTheDocumentsDirectoryWins(): void
    {
        $this->compile([
            ['documents_dir' => '/srv/first'],
            ['rate_lifetime' => 600],
            ['documents_dir' => '/srv/last'],
        ]);

        self::assertSame('/srv/last', $this->storageDirectory());
    }

    public function testWithoutADocumentsDirectoryTheStorageUsesTheDefault(): void
    {
        $this->compile([]);

        self::assertSame('/app/var/jpmmartin_carrier/documents', $this->storageDirectory());
    }

    public function testARetentionWrittenBelowTheLifetimeIsStillRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('rate_retention cannot be less than rate_lifetime');

        $this->compile([['rate_lifetime' => 900, 'rate_retention' => 10]]);
    }

    /**
     * Compared as written, "86400" and the placeholder of the variable are two strings, and "86400" sorts first: the
     * check used to fail on a configuration nothing was wrong with. It cannot be told here, so it is left to the use.
     */
    public function testALifetimeFromAVariableIsNotComparedWhenCompiling(): void
    {
        $container = $this->compile([['rate_lifetime' => '%env(int:CARRIER_RATE_LIFETIME)%', 'rate_retention' => 86400]]);

        self::assertSame(86400, $container->getParameter('jpmmartin_carrier.rate_retention'));
    }

    public function testARetentionFromAVariableIsNotComparedWhenCompiling(): void
    {
        $container = $this->compile([['rate_lifetime' => 900, 'rate_retention' => '%env(int:CARRIER_RATE_RETENTION)%']]);

        self::assertSame(900, $container->getParameter('jpmmartin_carrier.rate_lifetime'));
    }

    public function testALabelFormatWrittenThatTheCarrierDoesNotPrintIsStillRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('It offers GIF, ZPL, EPL and SPL.');

        $this->compile([['label_formats' => ['ups' => 'PDF']]]);
    }

    public function testAnEmptyLabelFormatWrittenIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->compile([['label_formats' => ['fedex' => '']]]);
    }

    /**
     * Without a default for the variable, Symfony checks the format against an empty string. Only the value the
     * variable has when a label is issued can say whether the carrier prints it.
     */
    public function testALabelFormatFromAVariableCompiles(): void
    {
        $container = $this->compile([['label_formats' => ['ups' => '%env(CARRIER_UPS_LABEL_FORMAT)%']]]);

        $formats = $container->getParameter('jpmmartin_carrier.label_formats');
        self::assertIsArray($formats);
        self::assertSame('%env(CARRIER_UPS_LABEL_FORMAT)%', $container->resolveEnvPlaceholders($formats['ups'] ?? null, '%%env(%s)%%'));
        self::assertSame('PDF', $formats['fedex'] ?? null);
    }

    /** @var array<array-key, mixed> The configurations the storage bundle was loaded with */
    private array $flysystemConfigs = [];

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function compile(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag([
            'kernel.project_dir' => '/app',
            'kernel.bundles' => [],
            'kernel.bundles_metadata' => [],
        ]));

        $container->registerExtension(new JpmMartinSyliusShippingCarriersExtension());
        foreach ($configs as $config) {
            $container->loadFromExtension(self::ALIAS, $config);
        }

        // Registered, as in every store: that is what makes the plugin declare its storage for documents.
        $flysystem = new class() extends Extension {
            /** @var array<array-key, mixed> */
            public array $configs = [];

            public function load(array $configs, ContainerBuilder $container): void
            {
                $this->configs = $configs;
            }

            public function getAlias(): string
            {
                return 'flysystem';
            }
        };
        $container->registerExtension($flysystem);
        $container->loadFromExtension('flysystem', []);

        (new MergeExtensionConfigurationPass())->process($container);

        $container->register('days_env_var_processor', DaysEnvVarProcessor::class)->addTag('container.env_var_processor');
        (new RegisterEnvVarProcessorsPass())->process($container);
        (new ValidateEnvPlaceholdersPass())->process($container);

        $this->flysystemConfigs = $flysystem->configs;

        return $container;
    }

    /**
     * The value a setting's parameter holds, with any variable written back as `%env(...)%`.
     */
    private function parameter(ContainerBuilder $container, string $setting): mixed
    {
        return $container->resolveEnvPlaceholders($container->getParameter('jpmmartin_carrier.' . $setting), '%%env(%s)%%');
    }

    private function storageDirectory(): mixed
    {
        $directory = null;
        foreach ($this->flysystemConfigs as $config) {
            if (!is_array($config) || !is_array($storages = $config['storages'] ?? null)) {
                continue;
            }

            $storage = $storages[JpmMartinSyliusShippingCarriersExtension::DOCUMENT_STORAGE] ?? null;
            if (is_array($storage) && is_array($options = $storage['options'] ?? null)) {
                $directory = $options['directory'] ?? $directory;
            }
        }

        return $directory;
    }
}
