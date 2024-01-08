<?php

namespace RollandRock\ParamConverterBundle\ValueResolver;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use RollandRock\ParamConverterBundle\Attribute\EntityArgument;
use RollandRock\ParamConverterBundle\Builder\EntityBuilder;
use RollandRock\ParamConverterBundle\Exception\FieldNotFoundInRequestException;
use RollandRock\ParamConverterBundle\Exception\MappedSuperclassDiscriminatorNotFoundInInheritanceMapException;
use RollandRock\ParamConverterBundle\Exception\MappedSuperclassDiscriminatorNotFoundInRequestException;
use RollandRock\ParamConverterBundle\Finder\RequestFinder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

readonly class EntityArgumentValueResolver implements ArgumentValueResolverInterface
{
    public function __construct(
        private EntityBuilder $builder,
        private EntityManagerInterface $entityManager,
        private RequestFinder $requestFinder,
    ) {}

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $attribute = $argument->getAttributes(EntityArgument::class)[0];
        $class = $argument->getType();
        $properties = $attribute->properties;

        if ($properties) {
            $entity = $this->retrieveEntity($class, $request, $properties);
        } else {
            $entity = $this->retrieveFromIdentifiers($class, $request);
        }

        try {
            $this->builder->buildEntity($entity, $request);
        } catch (MappedSuperclassDiscriminatorNotFoundInInheritanceMapException | MappedSuperclassDiscriminatorNotFoundInRequestException | MappingException) {
            throw new BadRequestHttpException();
        }

        yield $entity;
    }

    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        if (count($argument->getAttributes(EntityArgument::class)) === 0) {
            return false;
        }

        try {
            return $this->entityManager->getClassMetadata($argument->getType()) instanceof ClassMetadata;
        } catch (\Exception) {
            return false;
        }
    }

    private function retrieveFromIdentifiers(string $class, Request $request): object
    {
        return $this->retrieveEntity(
            $class,
            $request,
            $this->entityManager->getClassMetadata($class)->getIdentifierFieldNames()
        );
    }

    private function retrieveEntity(string $class, Request $request, array $identifiers): object
    {
        $search = [];

        foreach ($identifiers as $identifier) {
            try {
                $value = $this->requestFinder->find($identifier, $request);
                if ($value !== null) {
                    $search[$identifier] = $value;
                }
            } catch (FieldNotFoundInRequestException $e) {
                // continue
            }
        }

        if (count($search) === count($identifiers)) {
            $entity = $this->entityManager->getRepository($class)->findOneBy($search);
            if (!$entity) {
                $entity = new $class();
            }
        } else {
            $entity = new $class();
        }

        return $entity;
    }
}
