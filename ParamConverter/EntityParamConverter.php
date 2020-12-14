<?php

namespace RollandRock\ParamConverterBundle\ParamConverter;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use RollandRock\ParamConverterBundle\Builder\EntityBuilder;
use RollandRock\ParamConverterBundle\Exception\FieldNotFoundInRequestException;
use RollandRock\ParamConverterBundle\Finder\RequestFinder;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Request\ParamConverter\ParamConverterInterface;
use Symfony\Component\HttpFoundation\Request;

class EntityParamConverter implements ParamConverterInterface
{
    /**
     * @var EntityBuilder
     */
    private $builder;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var RequestFinder
     */
    private $requestFinder;

    /**
     * @param EntityBuilder $builder
     * @param EntityManager $entityManager
     * @param RequestFinder $requestFinder
     */
    public function __construct(EntityBuilder $builder, EntityManager $entityManager, RequestFinder $requestFinder)
    {
        $this->builder = $builder;
        $this->entityManager = $entityManager;
        $this->requestFinder = $requestFinder;
    }

    /**
     * {@inheritdoc}
     */
    function apply(Request $request, ParamConverter $configuration)
    {
        $class = $configuration->getClass();
        $options = $configuration->getOptions();

        if (isset($options['properties'])) {
            $entity = $this->retrieveEntity($class, $request, $options['properties']);
        } else {
            $entity = $this->retrieveFromIdentifiers($class, $request);
        }

        $this->builder->buildEntity($entity, $request);

        $request->attributes->set($configuration->getName(), $entity);
    }

    /**
     * {@inheritdoc}
     */
    function supports(ParamConverter $configuration)
    {
        $class = $configuration->getClass();

        if (!class_exists($class)) {
            return false;
        }

        try {
            return $this->entityManager->getClassMetadata($class) instanceof ClassMetadata;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $class
     * @param Request $request
     *
     * @return object
     */
    private function retrieveFromIdentifiers($class, Request $request)
    {
        return $this->retrieveEntity($class, $request, $this->entityManager->getClassMetadata($class)->getIdentifierFieldNames());
    }

    /**
     * @param string $class
     * @param Request $request
     * @param array $identifiers
     *
     * @return object
     */
    private function retrieveEntity($class, Request $request, array $identifiers)
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
