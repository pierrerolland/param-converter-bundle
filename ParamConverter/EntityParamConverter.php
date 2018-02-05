<?php

namespace RollandRock\ParamConverterBundle\ParamConverter;

use Doctrine\ORM\EntityManager;
use RollandRock\ParamConverterBundle\Builder\EntityBuilder;
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
        $identifiers = $this->entityManager->getClassMetadata($class)->getIdentifierFieldNames();

        $search = [];
        foreach ($identifiers as $identifier) {
            $value = $this->requestFinder->find($identifier, $request);
            if ($value !== null) {
                $search[$identifier] = $value;
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

        $this->builder->buildEntity($entity, $request);

        $request->attributes->set($configuration->getName(), $entity);
    }

    /**
     * {@inheritdoc}
     */
    function supports(ParamConverter $configuration)
    {
        $class = $configuration->getClass();

        return class_exists($class) && $this->entityManager->getClassMetadata($class);
    }
}
