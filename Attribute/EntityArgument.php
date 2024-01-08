<?php

declare(strict_types=1);

namespace RollandRock\ParamConverterBundle\Attribute;

use \Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class EntityArgument
{
    public array $properties = [];
}
