<?php

namespace RollandRock\ParamConverterBundle\Tests\ValueResolver;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RollandRock\ParamConverterBundle\Attribute\EntityArgument;
use RollandRock\ParamConverterBundle\Builder\EntityBuilder;
use RollandRock\ParamConverterBundle\Finder\RequestFinder;
use RollandRock\ParamConverterBundle\Tests\Fixtures\Article;
use RollandRock\ParamConverterBundle\Tests\Fixtures\Comment;
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

    public function testNewEntitiesWithNullCollectionsArePopulatedRecursively(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('findOneBy');
        $entityManager = $this->createEntityManagerWithAssociations();
        $entityManager->method('getRepository')->with(Comment::class)->willReturn($repository);
        $resolver = new ArgumentResolver(null, [$this->createResolver($entityManager)]);
        $request = new Request(content: '{"title":"New article","comments":[{"body":"New comment","replies":[{"body":"New reply"}]}]}');

        [$article] = $resolver->getArguments($request, static function (#[EntityArgument] Article $article): void {});

        self::assertSame('New article', $article->title);
        self::assertNull($article->id);
        self::assertCount(1, $article->comments);
        $comment = $article->comments[0];
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame('New comment', $comment->body);
        self::assertSame($article, $comment->article);
        self::assertCount(1, $comment->replies);
        $reply = $comment->replies[0];
        self::assertInstanceOf(Comment::class, $reply);
        self::assertSame('New reply', $reply->body);
        self::assertSame($comment, $reply->parent);
        self::assertNull($reply->replies);
    }

    #[DataProvider('nullableCollectionRequests')]
    public function testCollectionOnNewEntity(string $json, ?array $expected): void
    {
        $entityManager = $this->createEntityManagerWithAssociations();
        $entityManager->expects(self::never())->method('getRepository');
        $resolver = new ArgumentResolver(null, [$this->createResolver($entityManager)]);

        [$article] = $resolver->getArguments(new Request(content: $json), static function (#[EntityArgument] Article $article): void {});

        self::assertSame($expected, $article->comments);
    }

    public static function nullableCollectionRequests(): iterable
    {
        yield 'omitted collection stays null' => ['{}', null];
        yield 'empty collection initializes null' => ['{"comments":[]}', []];
        yield 'explicit null stays null' => ['{"comments":null}', null];
    }

    #[DataProvider('collectionTypes')]
    public function testExistingCollectionIsMerged(bool $useDoctrineCollection): void
    {
        $retained = new Comment();
        $retained->id = 1;
        $retained->body = 'Old comment';
        $removed = new Comment();
        $removed->id = 2;
        $article = new Article();
        $article->id = 42;
        $comments = [$retained, $removed];
        $article->comments = $useDoctrineCollection ? new ArrayCollection($comments) : $comments;
        $originalCollection = $article->comments;
        $articleRepository = $this->createMock(EntityRepository::class);
        $articleRepository->expects(self::once())->method('findOneBy')->with(['id' => 42])->willReturn($article);
        $commentRepository = $this->createMock(EntityRepository::class);
        $commentRepository->expects(self::never())->method('findOneBy');
        $entityManager = $this->createEntityManagerWithAssociations();
        $entityManager->method('getRepository')->willReturnMap([
            [Article::class, $articleRepository],
            [Comment::class, $commentRepository],
        ]);
        $resolver = new ArgumentResolver(null, [$this->createResolver($entityManager)]);
        $request = new Request(content: '{"id":42,"comments":[{"id":1,"body":"Updated comment"},{"body":"New comment"}]}');

        $arguments = $resolver->getArguments($request, static function (#[EntityArgument] Article $article): void {});

        self::assertSame([$article], $arguments);
        $comments = $useDoctrineCollection ? $article->comments->getValues() : array_values($article->comments);
        self::assertCount(2, $comments);
        self::assertSame($retained, $comments[0]);
        self::assertSame('Updated comment', $retained->body);
        self::assertSame($article, $retained->article);
        self::assertNotContains($removed, $comments);
        self::assertInstanceOf(Comment::class, $comments[1]);
        self::assertSame('New comment', $comments[1]->body);
        self::assertSame($article, $comments[1]->article);
        if ($useDoctrineCollection) {
            self::assertSame($originalCollection, $article->comments);
        }
    }

    public static function collectionTypes(): iterable
    {
        yield 'array' => [false];
        yield 'Doctrine collection' => [true];
    }

    private function createEntityManagerWithAssociations(): EntityManagerInterface
    {
        $articleMetadata = $this->createMetadata();
        $articleMetadata->mapOneToMany([
            'fieldName' => 'comments',
            'targetEntity' => Comment::class,
            'mappedBy' => 'article',
        ]);
        $commentMetadata = new ClassMetadata(Comment::class);
        $commentMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $commentMetadata->mapField(['fieldName' => 'body', 'type' => 'string']);
        $commentMetadata->mapManyToOne(['fieldName' => 'article', 'targetEntity' => Article::class]);
        $commentMetadata->mapManyToOne(['fieldName' => 'parent', 'targetEntity' => Comment::class]);
        $commentMetadata->mapOneToMany([
            'fieldName' => 'replies',
            'targetEntity' => Comment::class,
            'mappedBy' => 'parent',
        ]);
        $commentMetadata->initializeReflection(new RuntimeReflectionService());
        $commentMetadata->wakeupReflection(new RuntimeReflectionService());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnMap([
            [Article::class, $articleMetadata],
            [Comment::class, $commentMetadata],
        ]);

        return $entityManager;
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
