<?php

namespace RollandRock\ParamConverterBundle\Tests\Fixtures;

use Doctrine\Common\Collections\Collection;

class Article
{
    public ?int $id = null;
    public string $title = '';
    public array | Collection | null $comments = null;
}
