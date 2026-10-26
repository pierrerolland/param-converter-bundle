<?php

namespace RollandRock\ParamConverterBundle\Tests\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RollandRock\ParamConverterBundle\RollandRockParamConverterBundle;
use RollandRock\ParamConverterBundle\ValueResolver\EntityArgumentValueResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class RollandRockParamConverterExtensionTest extends TestCase
{
    public function testBundleServicesCompileAndTheResolverCanBeInstantiated(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.orm.entity_manager', EntityManagerInterface::class)
            ->setSynthetic(true)->setPublic(true);
        $container->register('property_accessor', PropertyAccessor::class);
        $bundle = new RollandRockParamConverterBundle();
        $extension = $bundle->getContainerExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias());
        $container->setAlias('test.entity_argument_resolver', 'rolland_rock_param_converter.value_resolver.entity')
            ->setPublic(true);
        $container->compile();
        $container->set('doctrine.orm.entity_manager', $this->createStub(EntityManagerInterface::class));

        $resolver = $container->get('test.entity_argument_resolver');

        self::assertInstanceOf(EntityArgumentValueResolver::class, $resolver);
        self::assertInstanceOf(ValueResolverInterface::class, $resolver);
        self::assertSame(
            [['priority' => 110]],
            $container->findDefinition('test.entity_argument_resolver')->getTag('controller.argument_value_resolver'),
        );
    }
}
