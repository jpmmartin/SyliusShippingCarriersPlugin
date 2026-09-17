<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Unit;

/**
 * The units the whole store is written in: variant weights and measures are shared by every
 * channel, so every origin declares the same units and the box catalog is read in them.
 */
interface StoreUnitsResolverInterface
{
    public function getWeightUnit(): string;

    public function getDimensionUnit(): string;
}
