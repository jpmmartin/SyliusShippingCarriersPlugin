<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\DependencyInjection;

use Doctrine\Bundle\MigrationsBundle\DependencyInjection\Configuration;
use Doctrine\Bundle\MigrationsBundle\DependencyInjection\DoctrineMigrationsExtension;
use JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\JpmMartinSyliusShippingCarriersExtension;
use PHPUnit\Framework\TestCase;
use SyliusLabs\DoctrineMigrationsExtraBundle\DependencyInjection\SyliusLabsDoctrineMigrationsExtraExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A store runs the plugin's migrations only if they are still listed once its own configuration is merged over
 * the plugin's.
 *
 * Sylius Standard lists its own migrations under the namespace `DoctrineMigrations`. A plugin that used the same
 * namespace would have its directory replaced by the store's when the two are merged, and `migrate` would answer
 * that there is nothing to do while none of the plugin's tables exists. The test application lists its own under
 * `App\Migrations`, which is why nothing else in the suite can see it.
 */
final class MigrationsNamespaceTest extends TestCase
{
    public function testTheMigrationsAreStillListedInAStoreThatListsItsOwnUnderDoctrineMigrations(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineMigrationsExtension());
        $container->registerExtension(new SyliusLabsDoctrineMigrationsExtraExtension());
        // What config/packages/doctrine_migrations.yaml of Sylius Standard 2.2 says.
        $container->loadFromExtension('doctrine_migrations', [
            'migrations_paths' => ['DoctrineMigrations' => '%kernel.project_dir%/migrations'],
        ]);

        (new JpmMartinSyliusShippingCarriersExtension())->prepend($container);

        /** @var array{migrations_paths: array<string, string>} $merged */
        $merged = (new Processor())->processConfiguration(new Configuration(), $container->getExtensionConfig('doctrine_migrations'));

        self::assertContains('@JpmMartinSyliusShippingCarriersPlugin/src/Migrations', $merged['migrations_paths']);
        self::assertSame('%kernel.project_dir%/migrations', $merged['migrations_paths']['DoctrineMigrations'] ?? null, 'The store keeps its own.');
    }

    public function testEveryMigrationIsInTheNamespaceThePluginDeclares(): void
    {
        $files = glob(__DIR__ . '/../../../src/Migrations/*.php');
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            self::assertStringContainsString(
                "\nnamespace JpmMartin\\SyliusShippingCarriersPlugin\\Migrations;\n",
                (string) file_get_contents($file),
                sprintf('%s is not in the namespace the plugin registers its migrations under, so no store would run it.', basename($file)),
            );
        }
    }
}
