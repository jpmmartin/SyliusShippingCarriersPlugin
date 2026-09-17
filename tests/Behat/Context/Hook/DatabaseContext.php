<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Hook;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sylius empties the database before each scenario. The PHPUnit tests share that database and expect it empty, so
 * it is emptied after each scenario as well.
 */
final readonly class DatabaseContext implements Context
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[AfterScenario]
    public function purgeDatabase(): void
    {
        $this->entityManager->clear();
        (new ORMPurger($this->entityManager))->purge();
        $this->entityManager->clear();
    }
}
