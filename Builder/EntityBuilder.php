<?php

namespace RollandRock\ParamConverterBundle\Builder;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ReflectionEnumProperty;
use RollandRock\ParamConverterBundle\Exception\FieldNotFoundInRequestException;
use RollandRock\ParamConverterBundle\Exception\MappedSuperclassDiscriminatorNotFoundInInheritanceMapException;
use RollandRock\ParamConverterBundle\Exception\MappedSuperclassDiscriminatorNotFoundInRequestException;
use RollandRock\ParamConverterBundle\Finder\RequestFinder;
use RollandRock\ParamConverterBundle\Utils\CollectionUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * Turns the request values into a fully usable entity
 *
 * @author Pierre Rolland <roll.pierre@gmail.com>
 */
readonly class EntityBuilder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyAccessor $propertyAccessor,
        private RequestFinder $requestFinder,
     ) {}

    /**
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \RollandRock\ParamConverterBundle\Exception\MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     * @throws \RollandRock\ParamConverterBundle\Exception\MappedSuperclassDiscriminatorNotFoundInRequestException
     */
    public function buildEntity(object $entity, Request $request, string $fromClass = ''): void
    {
        $metadata = $this->entityManager->getClassMetadata(get_class($entity));

        $this->fillFields($entity, $metadata, $request);
        $this->fillAssociations($entity, $metadata, $request, $fromClass);
    }

    private function fillFields(object $entity, ClassMetadata $metadata, Request $request): void
    {
        foreach ($metadata->getFieldNames() as $fieldName) {
            try {
                $value = $this->requestFinder->find($fieldName, $request);

                if ($this->propertyAccessor->isWritable($entity, $fieldName)) {
                    if (null !== $value) {
                        $fieldType = $metadata->getTypeOfField($fieldName);

                        if (($reflected = $metadata->getReflectionProperty($fieldName)) instanceof ReflectionEnumProperty) {
                            $value = $reflected->getType()->getName()::from($value);
                        } elseif (in_array($fieldType, ['integer', 'float', 'boolean', 'string'])) {
                            settype($value, $fieldType);
                        } elseif (in_array($fieldType, ['date', 'datetime']) && is_string($value)) {
                            $value = new \DateTime($value);
                        }
                    }
                    $this->propertyAccessor->setValue($entity, $fieldName, $value);
                }
            } catch (FieldNotFoundInRequestException $e) {
                // continue
            }
        }
    }

    /**
     * @throws MappedSuperclassDiscriminatorNotFoundInRequestException
     * @throws MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function fillAssociations(object $entity, ClassMetadata $metadata, Request $request, string $fromClass): void
    {
        foreach ($metadata->getAssociationNames() as $associationName) {
            $targetClass = $metadata->getAssociationTargetClass($associationName);

            try {
                $value = $this->requestFinder->find($associationName, $request);

                if (
                    $this->propertyAccessor->isWritable($entity, $associationName) &&
                    $fromClass !== $targetClass &&
                    null !== $value
                ) {
                    if ($metadata->isAssociationWithSingleJoinColumn($associationName)) {
                        $this->propertyAccessor->setValue(
                            $entity,
                            $associationName,
                            $this->retrieveAssociationValue(
                                $fromClass,
                                $targetClass,
                                $value
                            )
                        );
                    } else {
                        $mapping = $metadata->getAssociationMapping($associationName);
                        $this->propertyAccessor->setValue(
                            $entity,
                            $associationName,
                            $this->mergeCollection(
                                $entity,
                                $fromClass,
                                $targetClass,
                                $this->propertyAccessor->getValue($entity, $associationName),
                                $value,
                                $metadata->isAssociationInverseSide($associationName),
                                isset($mapping['mappedBy']) ? $mapping['mappedBy'] : null
                            )
                        );
                    }
                } elseif (null === $value) {
                    $this->propertyAccessor->setValue($entity, $associationName, null);
                }
            } catch (FieldNotFoundInRequestException $e) {
                // continue
            }
        }
    }

    /**
     * @throws MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     * @throws MappedSuperclassDiscriminatorNotFoundInRequestException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function retrieveAssociationValue(string $fromClass, string $targetClass, array $requestValues): object
    {
        $repo = $this->entityManager->getRepository($targetClass);
        $targetClassMetadata = $this->entityManager->getClassMetadata($targetClass);
        $identifiers = $targetClassMetadata->getIdentifierFieldNames();

        $search = [];
        foreach ($identifiers as $identifier) {
            if (isset($requestValues[$identifier])) {
                $search[$identifier] = $requestValues[$identifier];
            }
        }
        if (count($search) === count($identifiers)) {
            $value = $repo->findOneBy($search);
            if (!$value) {
                $value = $this->createNewClass($targetClass, $requestValues);
            }
        } else {
            $value = $this->createNewClass($targetClass, $requestValues);
        }
        $this->buildEntity($value, $this->getNewRequest($requestValues), $fromClass);

        return $value;
    }

    /**
     * @throws \RollandRock\ParamConverterBundle\Exception\MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \RollandRock\ParamConverterBundle\Exception\MappedSuperclassDiscriminatorNotFoundInRequestException
     */
    private function mergeCollection(
        object $entity,
        string $fromClass,
        string $targetClass,
        array | Collection $entityValues,
        array $requestValues,
        bool $isInverseSide,
        ?string $mappedBy
    ): iterable {
        $entityValues = $this->removeItemsNotInRequest($entityValues, $requestValues, $targetClass);
        $identifiers = $this->entityManager->getClassMetadata($targetClass)->getIdentifierFieldNames();

        foreach ($requestValues as $requestValue) {
            if (!CollectionUtils::isEmpty($entityValues) &&
                $this->requestValueHasIdentifiers($requestValue, $identifiers)) {
                foreach ($entityValues as $entityValue) {
                    if ($this->objectHasIdentifiersGetters($entity, $identifiers) &&
                        $this->objectIdentifiersMatchRequest($entityValue, $requestValue, $identifiers)) {
                        $this->buildEntity($entityValue, $this->getNewRequest($requestValue), $fromClass);
                        if ($isInverseSide && $mappedBy) {
                            $this->propertyAccessor->setValue($entityValue, $mappedBy, $entity);
                        }

                        continue 2;
                    }
                }
            }
            $entityValue = $this->retrieveAssociationValue($fromClass, $targetClass, $requestValue);
            if ($isInverseSide && $mappedBy) {
                $this->propertyAccessor->setValue($entityValue, $mappedBy, $entity);
            }

            CollectionUtils::add($entityValues, $entityValue);
        }

        return $entityValues;
    }

    private function removeItemsNotInRequest(array | Collection $entityValues, array $requestValues, string $entityClass): array | Collection
    {
        if (CollectionUtils::isEmpty($entityValues)) {
            return [];
        }

        $identifiers = $this->entityManager->getClassMetadata($entityClass)->getIdentifierFieldNames();

        foreach ($entityValues as $id => $entityValue) {
            if (!$this->objectHasIdentifiersGetters($entityValue, $identifiers)) {
                return [];
            }
            foreach ($requestValues as $requestValue) {
                if (
                    $this->requestValueHasIdentifiers($requestValue, $identifiers) &&
                    $this->objectIdentifiersMatchRequest($entityValue, $requestValue, $identifiers)
                ) {
                    continue 2;
                }
            }

            CollectionUtils::remove($entityValues, $id);
        }

        return $entityValues;
    }

    private function getNewRequest(array $values): Request
    {
        return new Request([], [], [], [], [], [], json_encode($values));
    }

    private function requestValueHasIdentifiers(array $requestValue, array $identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            if (!isset($requestValue[$identifier])) {
                return false;
            }
        }

        return true;
    }

    private function objectHasIdentifiersGetters(object $object, array $identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            if (!$this->propertyAccessor->isReadable($object, $identifier)) {
                return false;
            }
        }

        return true;
    }

    private function objectIdentifiersMatchRequest(object $object, array $requestValue, array $identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            if ($this->propertyAccessor->getValue($object, $identifier) != $requestValue[$identifier]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws MappedSuperclassDiscriminatorNotFoundInRequestException
     * @throws MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     */
    private function createNewClass(string $targetClass, array $requestValues): object
    {
        $targetClassMetadata = $this->entityManager->getClassMetadata($targetClass);

        if ($targetClassMetadata->isInheritanceTypeNone()) {
            return new $targetClass();
        }

        if (!array_key_exists($targetClassMetadata->discriminatorColumn['name'], $requestValues)) {
            throw new MappedSuperclassDiscriminatorNotFoundInRequestException($targetClass, $targetClassMetadata->discriminatorColumn['name']);
        }

        if (!array_key_exists($requestValues[$targetClassMetadata->discriminatorColumn['name']], $targetClassMetadata->discriminatorMap)) {
            throw new MappedSuperclassDiscriminatorNotFoundInInheritanceMapException($targetClass, $requestValues[$targetClassMetadata->discriminatorColumn['name']]);
        }

        return new $targetClassMetadata->discriminatorMap[$requestValues[$targetClassMetadata->discriminatorColumn['name']]]();
    }
}
