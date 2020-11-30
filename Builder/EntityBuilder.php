<?php

namespace RollandRock\ParamConverterBundle\Builder;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
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
class EntityBuilder
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var PropertyAccessor
     */
    private $propertyAccessor;

    /**
     * @var RequestFinder
     */
    private $requestFinder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param EntityManager $entityManager
     * @param PropertyAccessor $propertyAccessor
     * @param RequestFinder $requestFinder
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityManager $entityManager,
        PropertyAccessor $propertyAccessor,
        RequestFinder $requestFinder,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->propertyAccessor = $propertyAccessor;
        $this->requestFinder = $requestFinder;
        $this->logger = $logger;
    }

    /**
     * @param object $entity
     * @param Request $request
     * @param string $fromClass
     *
     * @throws MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     * @throws MappedSuperclassDiscriminatorNotFoundInRequestException
     */
    public function buildEntity($entity, Request $request, $fromClass = '')
    {
        $metadata = $this->entityManager->getClassMetadata(get_class($entity));

        $this->fillFields($entity, $metadata, $request);
        $this->fillAssociations($entity, $metadata, $request, $fromClass);
    }

    /**
     * Fills the $entity's column fields
     *
     * @param object $entity
     * @param ClassMetadata $metadata
     * @param Request $request
     */
    private function fillFields($entity, ClassMetadata $metadata, Request $request)
    {
        foreach ($metadata->getFieldNames() as $fieldName) {
            try {
                $value = $this->requestFinder->find($fieldName, $request);

                if ($this->propertyAccessor->isWritable($entity, $fieldName)) {
                    if (null !== $value) {
                        $fieldType = $metadata->getTypeOfField($fieldName);

                        if (in_array($fieldType, ['integer', 'float', 'boolean', 'string'])) {
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
     * Fills the $entity's association fields
     *
     * @param object $entity
     * @param ClassMetadata $metadata
     * @param Request $request
     * @param string $fromClass
     *
     * @throws MappedSuperclassDiscriminatorNotFoundInRequestException
     * @throws MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     */
    private function fillAssociations($entity, ClassMetadata $metadata, Request $request, $fromClass)
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
     * @param string $fromClass
     * @param string $targetClass
     * @param array $requestValues
     *
     * @return object
     *
     * @throws MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     * @throws MappedSuperclassDiscriminatorNotFoundInRequestException
     */
    private function retrieveAssociationValue($fromClass, $targetClass, array $requestValues)
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
     * @param object $entity
     * @param string $fromClass
     * @param string $targetClass
     * @param array|Collection $entityValues
     * @param array $requestValues
     * @param bool $isInverseSide
     * @param string $mappedBy
     *
     * @return array|Collection
     */
    private function mergeCollection(
        $entity,
        $fromClass,
        $targetClass,
        $entityValues,
        array $requestValues,
        $isInverseSide,
        $mappedBy
    ) {
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

    /**
     * @param array|Collection $entityValues
     * @param array $requestValues
     * @param string $entityClass
     *
     * @return array|Collection
     */
    private function removeItemsNotInRequest($entityValues, array $requestValues, $entityClass)
    {
        if (CollectionUtils::isEmpty($entityValues)) {
            return [];
        }

        $identifiers = $this->entityManager->getClassMetadata($entityClass)->getIdentifierFieldNames();

        foreach ($entityValues as $id => $entityValue) {
            if (!$this->objectHasIdentifiersGetters($entityValue, $identifiers)) {
                return;
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

    /**
     * @param array $values
     *
     * @return Request
     */
    private function getNewRequest($values)
    {
        return new Request([], [], [], [], [], [], json_encode($values));
    }

    /**
     * @param array $requestValue
     * @param array $identifiers
     *
     * @return bool
     */
    private function requestValueHasIdentifiers(array $requestValue, array $identifiers)
    {
        foreach ($identifiers as $identifier) {
            if (!isset($requestValue[$identifier])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param object $object
     * @param array $identifiers
     *
     * @return bool
     */
    private function objectHasIdentifiersGetters($object, array $identifiers)
    {
        foreach ($identifiers as $identifier) {
            if (!$this->propertyAccessor->isReadable($object, $identifier)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param object $object
     * @param array $requestValue
     * @param array $identifiers
     *
     * @return bool
     */
    private function objectIdentifiersMatchRequest($object, array $requestValue, array $identifiers)
    {
        foreach ($identifiers as $identifier) {
            if ($this->propertyAccessor->getValue($object, $identifier) != $requestValue[$identifier]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $targetClass
     * @param array $requestValues
     *
     * @return object
     *
     * @throws MappedSuperclassDiscriminatorNotFoundInRequestException
     * @throws MappedSuperclassDiscriminatorNotFoundInInheritanceMapException
     */
    private function createNewClass($targetClass, array $requestValues)
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
