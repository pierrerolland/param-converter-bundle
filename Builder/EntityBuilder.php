<?php

namespace RollandRock\ParamConverterBundle\Builder;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use RollandRock\ParamConverterBundle\Finder\RequestFinder;
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
            if (
                null !== ($value = $this->requestFinder->find($fieldName, $request)) &&
                $this->propertyAccessor->isWritable($entity, $fieldName)
            ) {
                $fieldType = $metadata->getTypeOfField($fieldName);
                if (in_array($fieldType, ['integer', 'float', 'boolean', 'string'])) {
                    settype($value, $metadata->getTypeOfField($fieldName));
                } elseif (in_array($fieldType, ['date', 'datetime']) && is_string($value)) {
                    $value = new \DateTime($value);
                }
                $this->propertyAccessor->setValue($entity, $fieldName, $value);
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
     */
    private function fillAssociations($entity, ClassMetadata $metadata, Request $request, $fromClass)
    {
        foreach ($metadata->getAssociationNames() as $associationName) {
            $targetClass = $metadata->getAssociationTargetClass($associationName);
            if (
                null !== ($value = $this->requestFinder->find($associationName, $request)) &&
                $this->propertyAccessor->isWritable($entity, $associationName) &&
                $fromClass !== $targetClass
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
            }
        }
    }

    /**
     * @param string $fromClass
     * @param string $targetClass
     * @param array $requestValues
     *
     * @return object
     */
    private function retrieveAssociationValue($fromClass, $targetClass, array $requestValues)
    {
        $repo = $this->entityManager->getRepository($targetClass);
        if (isset($requestValues['id'])) {
            $value = $repo->find($requestValues['id']);
            if (!$value) {
                $value = new $targetClass();
            }
        } else {
            $value = new $targetClass();
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
     * @return array|ArrayCollection
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
        $entityValues = $this->removeItemsNotInRequest($entityValues, $requestValues);
        $collection = is_array($entityValues) ? [] : new ArrayCollection();

        foreach ($requestValues as $requestValue) {
            if ($entityValues && isset($requestValue['id'])) {
                foreach ($entityValues as $entityValue) {
                    if (method_exists($entityValue, 'getId')) {
                        if ($entityValue->getId() == $requestValue['id']) {
                            $this->buildEntity($entityValue, $this->getNewRequest($requestValue), $fromClass);
                            if ($isInverseSide && $mappedBy) {
                                $this->propertyAccessor->setValue($entityValue, $mappedBy, $entity);
                            }
                            $collection[] = $entityValue;
                            continue 2;
                        }
                    }
                }
            }
            $entityValue = $this->retrieveAssociationValue($fromClass, $targetClass, $requestValue);
            if ($isInverseSide && $mappedBy) {
                $this->propertyAccessor->setValue($entityValue, $mappedBy, $entity);
            }
            $collection[] = $entityValue;
        }

        return $collection;
    }

    /**
     * @param array|Collection $entityValues
     * @param array $requestValues
     *
     * @return array|Collection
     */
    private function removeItemsNotInRequest($entityValues, array $requestValues)
    {
        if (!$entityValues) {
            return null;
        }
        foreach ($entityValues as $id => $entityValue) {
            if (!method_exists($entityValue, 'getId')) {
                return;
            }
            $entityValueId = $entityValue->getId();
            foreach ($requestValues as $requestValue) {
                if (isset($requestValue['id']) && $requestValue['id'] == $entityValueId) {
                    continue 2;
                }
            }

            if (is_array($entityValues)) {
                unset($entityValues[$id]);
            } elseif ($entityValues instanceof Collection) {
                $entityValues->remove($id);
            }
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
}
