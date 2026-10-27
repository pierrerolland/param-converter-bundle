<?php

namespace RollandRock\ParamConverterBundle\Tests\Fixtures;

use Doctrine\Common\Collections\Collection;

class Comment
{
    public ?int $id = null;
    public string $body = '';
    public ?Article $article = null;
    public ?self $parent = null;
    public array | Collection | null $replies = null;
}
