# Doctrine entity param converter

This param converter builds a full entity, and all its dependencies, based on an entring JSON body.

## Usage:

```php
<?php

namespace App\Controller;

use App\Entity\Article;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

class ArticleController extends Controller
{
    /**
     * @param Article $article
     *
     * @return Response
     *
     * @ParamConverter(
     *     name="article",
     *     class="App\Entity\Article",
     *     converter="rollandrock_entity_converter"
     * )
     */
    public function postAction(Article $article)
    {
        $this->get('app.manager.article')->save($article);

        return $this->handleResponse($article, Response::HTTP_CREATED);
    }
}
```

## Example: 

Let's imagine you have these tables in database :

*article*:

| id | title                            | content                                                                 |
| -- | -------------------------------- | ----------------------------------------------------------------------- |
|  1 | I love Taribo West's hairdresser | The mulet haircut, less than a simple fashion : a real state of mind... |

*category*:

| id | title   |
| -- | ------- |
|  1 | Tennis  |
|  2 | Clothes |

*article_category*:

| article_id | category_id |
| ---------- | ----------- |
|  1         | 1           |
|  1         | 2           |

And you post this article:
```json
{
   "id": 1,
   "title": "I love Tony Vairelles' hairdresser",
   "content": "The mulet haircut, more than a simple fashion : a real state of mind...",
   "categories": [
     {
        "id": 1,
        "title": "Football"
     },
     {
        "title": "Style"
     }
   ]
}
```

You will end up with these tables:

*article*:

| id | title                              | content                                                                 |
| -- | ---------------------------------- | ----------------------------------------------------------------------- |
|  1 | I love Tony Vairelles' hairdresser | The mulet haircut, more than a simple fashion : a real state of mind... |

*category*:

| id | title    |
| -- | -------- |
|  1 | Football |
|  2 | Clothes  |
|  3 | Style    |

*article_category*:

| article_id | category_id |
| ---------- | ----------- |
|  1         | 1           |
|  1         | 3           |

Exactly what had been defined inside your JSON object.
