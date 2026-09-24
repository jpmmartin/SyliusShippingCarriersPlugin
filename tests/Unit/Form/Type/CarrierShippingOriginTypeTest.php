<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Form\Type;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Form\Type\CarrierShippingOriginType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsFactory;

final class CarrierShippingOriginTypeTest extends TestCase
{
    private const SETTINGS = [
        'carrierTimeout',
        'rateLifetime',
        'rateRetention',
        'trackingLifetime',
        'documentsRetention',
        'upsLabelFormat',
        'upsServices',
        'fedexLabelFormat',
        'fedexServices',
    ];

    public function testTheOriginOfThePluginOffersTheSettingsOfItsChannel(): void
    {
        $fields = $this->fieldsFor(CarrierShippingOrigin::class);

        foreach (self::SETTINGS as $setting) {
            self::assertContains($setting, $fields);
        }
    }

    /**
     * A store whose own origin model knows nothing of these settings keeps the form it had: its channels are on the
     * configuration, and there is nothing in the model to put the fields in.
     */
    public function testAnOriginModelWithoutChannelSettingsKeepsTheFormItHad(): void
    {
        $model = $this->createStub(CarrierShippingOriginInterface::class);

        $fields = $this->fieldsFor($model::class);

        self::assertSame([], array_values(array_intersect(self::SETTINGS, $fields)));
        self::assertContains('street', $fields);
    }

    /**
     * Neither the directory nor the retention of files no row names belongs to a channel.
     */
    public function testWhatBelongsToTheWholeStoreIsNotOffered(): void
    {
        $fields = $this->fieldsFor(CarrierShippingOrigin::class);

        self::assertNotContains('documentsDir', $fields);
        self::assertNotContains('temporaryDocumentsRetention', $fields);
    }

    /**
     * @param class-string $dataClass
     *
     * @return list<string> The fields the form is built with
     */
    private function fieldsFor(string $dataClass): array
    {
        $fields = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name) use (&$fields, $builder): FormBuilderInterface {
            $fields[] = $name;

            return $builder;
        });
        $builder->method('addEventListener')->willReturn($builder);
        $builder->method('get')->willReturn($this->createStub(FormBuilderInterface::class));

        (new CarrierShippingOriginType($dataClass, ['sylius'], CarrierPackageBox::class, CarrierSettingsFactory::provider()))
            ->buildForm($builder, []);

        return $fields;
    }
}
