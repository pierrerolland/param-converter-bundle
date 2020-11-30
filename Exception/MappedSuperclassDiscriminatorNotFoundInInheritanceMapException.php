<?php

namespace RollandRock\ParamConverterBundle\Exception;

class MappedSuperclassDiscriminatorNotFoundInInheritanceMapException extends \Exception
{
    /**
     * @param string $targetClass
     * @param string $discriminatorValue
     * @param int $code
     * @param \Exception|null $previous
     */
    public function __construct($targetClass, $discriminatorValue, $code = 0, $previous = null)
    {
        parent::__construct(
            sprintf(
                'The superclass %s cannot be resolved as the discrimator value "%s" does not match any subclass',
                $targetClass,
                $discriminatorValue
            ),
            $code,
            $previous
        );
    }
}
