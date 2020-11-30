<?php

namespace RollandRock\ParamConverterBundle\Exception;

class MappedSuperclassDiscriminatorNotFoundInRequestException extends \Exception
{
    /**
     * @param string $targetClass
     * @param string $discriminatorColumn
     * @param int $code
     * @param \Exception|null $previous
     */
    public function __construct($targetClass, $discriminatorColumn, $code = 0, $previous = null)
    {
        parent::__construct(
            sprintf(
                'The superclass %s cannot be resolved as the discrimator value "%s" has not been found in the request',
                $targetClass,
                $discriminatorColumn
            ),
            $code,
            $previous
        );
    }
}
