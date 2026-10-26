<?php

namespace RollandRock\ParamConverterBundle\Tests\ValueResolver;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\TestCase;
use RollandRock\ParamConverterBundle\Attribute\EntityArgument;
use RollandRock\ParamConverterBundle\Builder\EntityBuilder;
use RollandRock\ParamConverterBundle\Finder\RequestFinder;
use RollandRock\ParamConverterBundle\Tests\Fixtures\Article;
use RollandRock\ParamConverterBundle\ValueResolver\EntityArgumentValueResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\PropertyAccess\PropertyAccess;

class EntityArgumentValueResolverTest extends TestCase
{
    public function testUnannotatedArgumentsAreLeftForOtherResolvers(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getClassMetadata');
        $resolver = new ArgumentResolver(null, [
            $this->createResolver($entityManager),
            ...ArgumentResolver::getDefaultArgumentValueResolvers(),
        ]);
        $request = new Request([], [], ['title' => 'From the route']);

        self::assertSame(
            ['From the route', $request],
            $resolver->getArguments($request, static function (string $title, Request $request): void {}),
        );
    }

    public function testAnnotatedArgumentWithoutATypeIsSkipped(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getClassMetadata');
        $argument = new ArgumentMetadata('article', null, false, false, null, false, [new EntityArgument()]);

        self::assertSame([], iterator_to_array($this->createResolver($entityManager)->resolve(new Request(), $argument)));
    }

    public function testUnmappedTypesAreSkipped(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getClassMetadata')
            ->with(\stdClass::class)
            ->willThrowException(new \RuntimeException('Not a mapped entity.'));
        $argument = new ArgumentMetadata('article', \stdClass::class, false, false, null, false, [new EntityArgument()]);

        self::assertSame([], iterator_to_array($this->createResolver($entityManager)->resolve(new Request(), $argument)));
    }

    public function testAnnotatedEntityIsCreatedAndPopulatedFromJson(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->with(Article::class)->willReturn($this->createMetadata());
        $entityManager->expects(self::never())->method('getRepository');
        $resolver = new ArgumentResolver(null, [$this->createResolver($entityManager)]);
        $request = new Request(content: '{"title":"New article"}');

        $arguments = $resolver->getArguments($request, static function (#[EntityArgument] Article $article): void {});

        self::assertCount(1, $arguments);
        self::assertInstanceOf(Article::class, $arguments[0]);
        self::assertSame('New article', $arguments[0]->title);
        self::assertNull($arguments[0]->id);
    }

    public function testExistingEntityIsRetrievedAndUpdated(): void
    {
        $article = new Article();
        $article->id = 42;
        $article->title = 'Old title';
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findOneBy')->with(['id' => 42])->willReturn($article);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->with(Article::class)->willReturn($this->createMetadata());
        $entityManager->method('getRepository')->with(Article::class)->willReturn($repository);
        $resolver = new ArgumentResolver(null, [$this->createResolver($entityManager)]);
        $request = new Request(content: '{"id":42,"title":"Updated title"}');

        $arguments = $resolver->getArguments($request, static function (#[EntityArgument] Article $article): void {});

        self::assertSame([$article], $arguments);
        self::assertSame('Updated title', $article->title);
    }

    private function createResolver(EntityManagerInterface $entityManager): EntityArgumentValueResolver
    {
        $finder = new RequestFinder();

        return new EntityArgumentValueResolver(
            new EntityBuilder($entityManager, PropertyAccess::createPropertyAccessor(), $finder),
            $entityManager,
            $finder,
        );
    }

    private function createMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(Article::class);
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->mapField(['fieldName' => 'title', 'type' => 'string']);
        $metadata->initializeReflection(new RuntimeReflectionService());
        $metadata->wakeupReflection(new RuntimeReflectionService());

        return $metadata;
    }
}
